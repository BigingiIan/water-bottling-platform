"""Smoke test: the dashboard renders every tab against the real saved artifacts."""

import pytest

pytest.importorskip("streamlit")
from streamlit.testing.v1 import AppTest  # noqa: E402

from dashboard import data  # noqa: E402


@pytest.mark.skipif(data.find_metrics_dir() is None, reason="train the models first (no metrics files found)")
def test_dashboard_renders_without_exceptions():
    app = AppTest.from_file(str(data.ML_ROOT / "dashboard" / "app.py"), default_timeout=120).run()
    assert not app.exception
    assert [tab.label for tab in app.tabs] == ["Overview", "Forecast", "Models", "Stackers", "Folds"]
    assert any(metric.label.startswith("RMSE") for metric in app.metric)
