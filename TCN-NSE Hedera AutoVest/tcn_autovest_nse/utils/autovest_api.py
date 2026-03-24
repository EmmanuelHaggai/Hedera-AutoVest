import json
import pandas as pd
import requests

def post_signal(url: str, api_key: str, timestamp, ticker: str, position: float, confidence: float, price: float, strategy: str):
    payload = {
        "ts": pd.Timestamp(timestamp).isoformat(),
        "ticker": ticker,
        "position": float(position),
        "confidence": float(confidence),
        "price": float(price),
        "strategy": strategy
    }
    headers = {"Content-Type": "application/json"}
    if api_key:
        headers["X-API-Key"] = api_key
    r = requests.post(url, data=json.dumps(payload), headers=headers, timeout=10)
    return r.status_code, r.text
