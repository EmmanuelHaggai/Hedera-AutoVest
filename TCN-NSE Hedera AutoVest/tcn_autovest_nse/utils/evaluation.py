import os
import matplotlib.pyplot as plt
import pandas as pd
from sklearn.metrics import (
    mean_squared_error, mean_absolute_error, r2_score,
    median_absolute_error, explained_variance_score
)

def ensure_dir(path: str):
    if not os.path.exists(path):
        os.makedirs(path, exist_ok=True)

def save_metrics(path: str, y_true, y_pred, final_equity: float):
    mse = mean_squared_error(y_true, y_pred)
    mae = mean_absolute_error(y_true, y_pred)
    r2  = r2_score(y_true, y_pred)
    medae = median_absolute_error(y_true, y_pred)
    evs = explained_variance_score(y_true, y_pred)
    with open(path, "w") as f:
        f.write(f"MSE: {mse:.6e}\n")
        f.write(f"MAE: {mae:.6e}\n")
        f.write(f"R2: {r2:.6f}\n")
        f.write(f"MedianAE: {medae:.6e}\n")
        f.write(f"ExplainedVariance: {evs:.6f}\n")
        f.write(f"Final equity: {final_equity:.2%}\n")

def plot_returns(results: pd.DataFrame, out_path: str):
    plt.figure(figsize=(10,4))
    plt.plot(results.index, results["y_next_ret"], label="Actual next ret")
    plt.plot(results.index, results["pred_ret"], label="Pred next ret")
    plt.title("Next-bar returns: actual vs predicted")
    plt.legend(); plt.grid(True); plt.tight_layout()
    plt.savefig(out_path); plt.close()

def plot_equity(results: pd.DataFrame, out_path: str):
    plt.figure(figsize=(10,4))
    plt.plot(results.index, results["equity_curve"], label="Equity curve")
    plt.title("Strategy equity curve")
    plt.legend(); plt.grid(True); plt.tight_layout()
    plt.savefig(out_path); plt.close()
