"""Locate and load the saved artifacts and metrics files the dashboard reads."""

from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path

import pandas as pd
import streamlit as st

ML_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = ML_ROOT.parent
ROLLING_DIR = ML_ROOT / "outputs" / "rolling_origin"
SKUS = ["500ml", "1L", "5L", "10L", "20L"]
MEAN_ROW = "Mean of SKUs"
HORIZON_LABELS = {
    "h1": "1 day ahead",
    "h7": "7 days ahead",
    "h14": "14 days ahead",
    "h28": "28 days ahead",
    "h1-28 mean": "Average of days 1 to 28",
}


def _is_file(path: Path) -> bool:
    try:
        return path.is_file()
    except OSError:
        return False


def candidate_dirs() -> list[Path]:
    """Folders that may hold bundles and metrics, most specific first."""
    found: list[Path] = []
    if os.environ.get("ML_MODEL_DIR"):
        found.append(Path(os.environ["ML_MODEL_DIR"]))
    found += [ML_ROOT / "saved_models", ML_ROOT / "outputs" / "saved_models"]
    return found


def find_metrics_dir() -> Path | None:
    for folder in candidate_dirs():
        if _is_file(folder / "metrics_manifest.json"):
            return folder
    return None


def find_model_dir() -> Path | None:
    for folder in candidate_dirs():
        if any(_is_file(folder / sku / "manifest.json") for sku in SKUS):
            return folder
    return None


def data_csv_path() -> Path:
    return Path(os.environ.get("DASHBOARD_DATA_CSV", REPO_ROOT / "data" / "kenya_water_demand_daily.csv"))


@dataclass(frozen=True)
class Artifacts:
    metrics_dir: Path | None
    model_dir: Path | None


def _has_bundles(folder: Path | None) -> bool:
    return folder is not None and any(_is_file(folder / sku / "manifest.json") for sku in SKUS)


def locate() -> Artifacts:
    """Prefer bundles that sit next to the metrics files, so both come from one training run."""
    metrics_dir = find_metrics_dir()
    model_dir = metrics_dir if _has_bundles(metrics_dir) else find_model_dir()
    return Artifacts(metrics_dir, model_dir)


def _stamp(path: Path) -> float:
    try:
        return path.stat().st_mtime
    except OSError:
        return 0.0


@st.cache_data(show_spinner=False)
def _read_csv(path: str, stamp: float) -> pd.DataFrame:  # stamp invalidates the cache on change
    return pd.read_csv(path)


@st.cache_data(show_spinner=False)
def _read_json(path: str, stamp: float) -> dict:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def read_csv(path: Path) -> pd.DataFrame | None:
    return _read_csv(str(path), _stamp(path)) if _is_file(path) else None


def read_json(path: Path) -> dict | None:
    return _read_json(str(path), _stamp(path)) if _is_file(path) else None


def sku_manifests(model_dir: Path | None) -> dict[str, dict]:
    if model_dir is None:
        return {}
    found = {}
    for sku in SKUS:
        manifest = read_json(model_dir / sku / "manifest.json")
        if manifest:
            found[sku] = manifest
    return found


@st.cache_data(show_spinner=False)
def _read_history(path: str, stamp: float) -> pd.DataFrame:
    frame = pd.read_csv(path, parse_dates=["date"])
    for column in ("is_promotion", "is_public_holiday", "is_school_term"):
        frame[column] = frame[column].astype(bool)
    return frame.sort_values(["product_sku", "date"]).reset_index(drop=True)


def read_history() -> pd.DataFrame | None:
    path = data_csv_path()
    return _read_history(str(path), _stamp(path)) if _is_file(path) else None


def stacker_files(period: str) -> dict[str, Path]:
    """period is 'final_test' or 'development'."""
    folder = ROLLING_DIR / ("stacking_final_test" if period == "final_test" else "stacking")
    return {
        "summary": folder / "stacker_summary.csv",
        "weights": folder / "stacker_weights.csv",
        "selection": folder / "stacker_selection.json",
    }
