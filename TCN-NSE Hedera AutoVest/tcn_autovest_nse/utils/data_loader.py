import os
import numpy as np
import pandas as pd

def _try_yfinance_download(ticker: str):
    try:
        import yfinance as yf
        df = yf.download(ticker, auto_adjust=False)
        if isinstance(df, pd.DataFrame) and len(df) > 0:
            df = df.reset_index()
            return df
        return None
    except Exception:
        return None

def load_price_data(ticker: str | None, csv_path: str | None) -> pd.DataFrame:
    if ticker:
        df = _try_yfinance_download(ticker)
        if df is not None and len(df):
            return df
    if not csv_path or not os.path.exists(csv_path):
        raise FileNotFoundError("Provide a working Yahoo ticker or a valid CSV path.")
    df = pd.read_csv(csv_path)
    return df

def prepare_columns(df: pd.DataFrame) -> pd.DataFrame:
    keep = ["Date", "Open", "High", "Low", "Close", "Volume"]
    for col in keep:
        if col not in df.columns:
            raise ValueError(f"Missing column: {col}")
    df = df[keep].copy()
    df["Date"] = pd.to_datetime(df["Date"])
    for c in ["Open", "High", "Low", "Close", "Volume"]:
        df[c] = pd.to_numeric(df[c], errors="coerce")
    df = df.dropna().sort_values("Date").reset_index(drop=True)
    return df

def add_features(df: pd.DataFrame, vol_lookback: int, use_features: list[str]) -> pd.DataFrame:
    data = df.copy()
    data["ret1"] = data["Close"].pct_change()
    data["hl_spread"] = (data["High"] - data["Low"]) / data["Close"].replace(0, np.nan)
    data["oc_spread"] = (data["Open"] - data["Close"]) / data["Close"].replace(0, np.nan)
    data["vol_roll"] = data["ret1"].rolling(vol_lookback).std()
    data["y_next_ret"] = data["Close"].pct_change().shift(-1)
    feat_cols = use_features + ["ret1", "hl_spread", "oc_spread", "vol_roll"]
    data = data.dropna(subset=feat_cols + ["y_next_ret"]).reset_index(drop=True)
    return data

def make_windows(df: pd.DataFrame, lookback: int, use_features: list[str]):
    feat_cols = use_features + ["ret1", "hl_spread", "oc_spread", "vol_roll"]
    feats = df[feat_cols].values
    y = df["y_next_ret"].values
    dates = pd.to_datetime(df["Date"]).values

    X_list, y_list, d_list = [], [], []
    for i in range(lookback, len(df)):
        X_list.append(feats[i - lookback:i])
        y_list.append(y[i])
        d_list.append(dates[i])
    X = np.array(X_list, dtype=np.float32)
    y = np.array(y_list, dtype=np.float32)
    return X, y, pd.to_datetime(d_list)

