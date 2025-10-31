import os
import numpy as np
import pandas as pd
import tensorflow as tf

from sklearn.preprocessing import MinMaxScaler

from utils.config import CONFIG
from utils.data_loader import load_price_data, prepare_columns, add_features, make_windows
from utils.evaluation import ensure_dir, save_metrics, plot_returns, plot_equity
from utils.autovest_api import post_signal
from models.tcn_model import build_tcn
from models.risk_map import risk_map_to_position, apply_slippage, confidence_score

def select_device(pref: str):
    if pref == "auto":
        return "/GPU:0" if tf.config.list_physical_devices('GPU') else "/CPU:0"
    return pref

def main():
    out_root = "data/results"
    ensure_dir(out_root)
    logs_root = "logs"
    ensure_dir(logs_root)

    cfg = CONFIG
    device = select_device(cfg["device"])

    raw = load_price_data(cfg["ticker"], cfg["csv_path"])
    raw = prepare_columns(raw)
    data = add_features(raw, cfg["vol_lookback"], cfg["use_features"])

    # scale only features
    feat_cols = cfg["use_features"] + ["ret1", "hl_spread", "oc_spread", "vol_roll"]
    scaler = MinMaxScaler()
    data[feat_cols] = scaler.fit_transform(data[feat_cols])

    X, y, d_idx = make_windows(data, cfg["lookback"], cfg["use_features"])

    preds, vols, dates, prices, y_true = [], [], [], [], []

    with tf.device(device):
        i = cfg["train_min_size"]
        while i < len(X):
            X_train = X[:i - cfg["val_size"]]
            y_train = y[:i - cfg["val_size"]]
            X_val = X[i - cfg["val_size"]:i]
            y_val = y[i - cfg["val_size"]:i]

            model = build_tcn(
                input_shape=(X.shape[1], X.shape[2]),
                learning_rate=cfg["learning_rate"]
            )
            model.fit(
                X_train, y_train,
                validation_data=(X_val, y_val),
                epochs=cfg["epochs"],
                batch_size=cfg["batch_size"],
                verbose=0
            )

            X_test = X[i:i+1]
            y_test = y[i:i+1]
            p = model.predict(X_test, verbose=0)[0][0]

            # recompute realized vol from raw closes
            look = cfg["vol_lookback"]
            close = raw["Close"].values
            # map window to raw index
            raw_idx = i + cfg["lookback"]
            start = max(raw_idx - look, 1)
            vol_est = float(np.nanstd((close[start:raw_idx] / close[start-1:raw_idx-1]) - 1.0)) if raw_idx > start else 0.0

            preds.append(float(p))
            vols.append(vol_est)
            dates.append(d_idx[i])
            prices.append(float(raw["Close"].iloc[raw_idx]))
            y_true.append(float(y_test[0]))

            i += cfg["step"]

    results = pd.DataFrame({
        "Date": pd.to_datetime(dates),
        "price": prices,
        "y_next_ret": y_true,
        "pred_ret": preds,
        "vol_est": vols
    }).set_index("Date")

    positions, confs = [], []
    for r in results.itertuples():
        pos = risk_map_to_position(r.pred_ret, r.vol_est if r.vol_est > 0 else 1e-6, cfg["deadband"], cfg["max_exposure"])
        positions.append(pos)
        confs.append(confidence_score(r.pred_ret, r.vol_est))
    results["position"] = positions
    results["confidence"] = confs

    pnl, cum, prev_pos = [], 1.0, 0.0
    for r in results.itertuples():
        turnover = abs(r.position - prev_pos)
        net_ret = apply_slippage(r.y_next_ret * r.position, turnover, cfg["slippage_bps"])
        cum *= (1.0 + net_ret)
        pnl.append(cum - 1.0)
        prev_pos = r.position
    results["equity_curve"] = pnl

    out_prefix = os.path.join(out_root, f"NSE_TCN_{cfg['ticker'] or os.path.basename(cfg['csv_path'] or 'CSV')}")
    results.to_csv(out_prefix + "_results.csv")

    save_metrics(os.path.join("logs", "metrics.txt"), results["y_next_ret"], results["pred_ret"], results["equity_curve"].iloc[-1])
    plot_returns(results, out_prefix + "_ret_pred.png")
    plot_equity(results, out_prefix + "_equity.png")

    if cfg["live_mode"] and len(results) > 0:
        last = results.iloc[-1]
        code, txt = post_signal(
            url=cfg["autovest_webhook_url"],
            api_key=cfg["autovest_api_key"],
            timestamp=results.index[-1],
            ticker=cfg["ticker"] or os.path.basename(cfg["csv_path"] or "CSV").split(".")[0],
            position=float(last["position"]),
            confidence=float(last["confidence"]),
            price=float(last["price"]),
            strategy="TCN_NSE_v1"
        )
        print("AutoVest:", code, txt[:200])

    print("Done. Outputs:", {
        "csv": out_prefix + "_results.csv",
        "plots": [out_prefix + "_ret_pred.png", out_prefix + "_equity.png"]
    })

if __name__ == "__main__":
    main()
