#!/usr/bin/env python3
"""Read-only demo monitor for a 1C exchange queue snapshot (standard library only)."""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from pathlib import Path
from typing import Any


def parse_dt(value: Any, field: str) -> datetime:
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f"Поле {field!r} должно содержать дату ISO 8601")
    try:
        dt = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ValueError(f"Некорректная дата в поле {field!r}: {value!r}") from exc
    if dt.tzinfo is None:
        raise ValueError(f"В поле {field!r} нужен часовой пояс (например, +03:00)")
    return dt


def minutes_between(later: datetime, earlier: datetime) -> float:
    return max(0.0, (later - earlier).total_seconds() / 60.0)


def load_json(path: Path) -> Any:
    try:
        with path.open("r", encoding="utf-8") as handle:
            return json.load(handle)
    except OSError as exc:
        raise ValueError(f"Не удалось прочитать {path}: {exc}") from exc
    except json.JSONDecodeError as exc:
        raise ValueError(f"Некорректный JSON в {path}, строка {exc.lineno}: {exc.msg}") from exc


def validate_job(job: Any, index: int) -> dict[str, Any]:
    if not isinstance(job, dict):
        raise ValueError(f"Задание #{index}: ожидался объект JSON")
    for field in ("id", "order_no", "status", "created_at", "attempts"):
        if field not in job:
            raise ValueError(f"Задание #{index}: отсутствует поле {field!r}")
    if not isinstance(job["attempts"], int) or job["attempts"] < 0:
        raise ValueError(f"Задание #{index}: attempts должно быть целым числом >= 0")
    if not isinstance(job["status"], str):
        raise ValueError(f"Задание #{index}: status должно быть строкой")
    job = dict(job)
    job["created_dt"] = parse_dt(job["created_at"], f"jobs[{index}].created_at")
    job["last_error"] = str(job.get("last_error", ""))
    return job


def analyse(snapshot: Any, config: Any) -> tuple[str, list[str], str]:
    if not isinstance(snapshot, dict):
        raise ValueError("Корень снимка должен быть JSON-объектом")
    if not isinstance(config, dict):
        raise ValueError("Конфигурация порогов должна быть JSON-объектом")
    try:
        now = parse_dt(snapshot.get("checked_at"), "checked_at")
        last_success = parse_dt(snapshot.get("last_success_at"), "last_success_at")
    except ValueError:
        raise

    jobs_raw = snapshot.get("jobs")
    if not isinstance(jobs_raw, list):
        raise ValueError("Поле 'jobs' должно быть массивом")
    jobs: list[dict[str, Any]] = []
    bad_records: list[str] = []
    for idx, raw_job in enumerate(jobs_raw, start=1):
        try:
            jobs.append(validate_job(raw_job, idx))
        except ValueError as exc:
            # Do not crash the whole inspection because of a single malformed job.
            bad_records.append(str(exc))

    pending = [j for j in jobs if j["status"].lower() in {"pending", "processing", "queued"}]
    failed = [j for j in jobs if j["status"].lower() in {"failed", "error"}]
    oldest_age = max((minutes_between(now, j["created_dt"]) for j in pending), default=0.0)
    success_age = minutes_between(now, last_success)
    pending_count = len(pending)
    failed_count = len(failed)
    max_attempts = max((j["attempts"] for j in jobs), default=0)

    required = {
        "warning_pending_count": 5,
        "critical_oldest_pending_minutes": 15,
        "critical_last_success_minutes": 10,
        "warning_failed_count": 1,
        "warning_attempts": 5,
    }
    thresholds: dict[str, float] = {}
    for name, default in required.items():
        value = config.get(name, default)
        if not isinstance(value, (int, float)) or value < 0:
            raise ValueError(f"Порог {name!r} должен быть неотрицательным числом")
        thresholds[name] = float(value)

    critical: list[str] = []
    warnings: list[str] = []
    if success_age > thresholds["critical_last_success_minutes"]:
        critical.append(
            f"Последняя успешная передача была {success_age:.1f} мин назад "
            f"(порог: {thresholds['critical_last_success_minutes']:.0f} мин)."
        )
    if pending and oldest_age > thresholds["critical_oldest_pending_minutes"]:
        critical.append(
            f"Самое старое ожидающее задание находится в очереди {oldest_age:.1f} мин "
            f"(порог: {thresholds['critical_oldest_pending_minutes']:.0f} мин)."
        )
    if pending_count >= thresholds["warning_pending_count"]:
        warnings.append(f"В очереди {pending_count} ожидающих/обрабатываемых заданий.")
    if failed_count >= thresholds["warning_failed_count"]:
        warnings.append(f"Есть задания с ошибкой: {failed_count}.")
    if max_attempts >= thresholds["warning_attempts"]:
        warnings.append(f"Максимальное число попыток одного задания: {max_attempts}.")
    if bad_records:
        warnings.append(f"Некорректных записей пропущено: {len(bad_records)}.")

    if critical:
        level = "CRITICAL"
    elif warnings:
        level = "WARNING"
    else:
        level = "OK"

    lines = [
        "МОНИТОРИНГ ОБМЕНА С 1С — ДЕМОНСТРАЦИОННЫЕ ДАННЫЕ",
        f"Проверено: {now.isoformat()}",
        f"Уровень: {level}",
        "",
        "МЕТРИКИ",
        f"  Заданий в снимке: {len(jobs)}",
        f"  Ожидают/обрабатываются: {pending_count}",
        f"  С ошибкой: {failed_count}",
        f"  Возраст самого старого ожидающего: {oldest_age:.1f} мин",
        f"  Время с последней успешной передачи: {success_age:.1f} мин",
        f"  Максимум попыток: {max_attempts}",
        "",
        "ПРИЧИНЫ/СИГНАЛЫ",
    ]
    if not critical and not warnings:
        lines.append("  Критических признаков по заданным порогам не найдено.")
    for item in critical:
        lines.append(f"  [CRITICAL] {item}")
    for item in warnings:
        lines.append(f"  [WARNING] {item}")
    if bad_records:
        lines.extend(["", "НЕКОРРЕКТНЫЕ ЗАПИСИ"])
        lines.extend(f"  - {item}" for item in bad_records)

    if critical or warnings:
        lines.extend(["", "РЕКОМЕНДОВАННЫЕ ДЕЙСТВИЯ"])
        lines.append("  1. Проверить доступность HTTP-сервиса обмена 1С и последние ошибки worker.")
        if failed:
            lines.append("  2. Открыть карточки заданий со статусом failed и проверить текст последней ошибки.")
        else:
            lines.append("  2. Найти самое старое ожидающее задание и проверить, движется ли очередь.")
        lines.append("  3. Не удалять и не перезапускать задания автоматически до выяснения причины.")
    else:
        lines.extend(["", "РЕКОМЕНДАЦИЯ", "  Продолжить наблюдение; проверка не обнаружила превышения порогов."])

    lines.extend(["", "ЗАДАНИЯ, ТРЕБУЮЩИЕ ВНИМАНИЯ"])
    attention = [j for j in jobs if j["status"].lower() in {"failed", "error"} or j["attempts"] >= thresholds["warning_attempts"]]
    if not attention:
        lines.append("  Нет.")
    else:
        for job in attention:
            err = job["last_error"].strip() or "ошибка не указана"
            lines.append(f"  - id={job['id']}, order={job['order_no']}, status={job['status']}, attempts={job['attempts']}; {err}")

    # Exit status can be used by a scheduler: 0 OK, 1 warning, 2 critical.
    exit_code = 2 if level == "CRITICAL" else 1 if level == "WARNING" else 0
    return level, lines, str(exit_code)


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only монитор очереди обмена с 1С по JSON-снимку")
    parser.add_argument("--input", type=Path, default=Path("data/queue_snapshot.json"), help="путь к снимку JSON")
    parser.add_argument("--config", type=Path, default=Path("config.json"), help="путь к конфигурации порогов")
    parser.add_argument("--output", type=Path, help="необязательный файл для сохранения отчёта")
    args = parser.parse_args()
    try:
        snapshot = load_json(args.input)
        config = load_json(args.config)
        _level, lines, exit_code_text = analyse(snapshot, config)
        report = "\n".join(lines) + "\n"
        print(report, end="")
        if args.output:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(report, encoding="utf-8")
        return int(exit_code_text)
    except ValueError as exc:
        print(f"ОШИБКА ВХОДНЫХ ДАННЫХ: {exc}", file=sys.stderr)
        return 3
    except OSError as exc:
        print(f"ОШИБКА ФАЙЛА: {exc}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
