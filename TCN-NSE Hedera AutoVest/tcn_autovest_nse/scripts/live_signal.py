import time
import numpy as np
import pandas as pd
from datetime import datetime

from utils.config import CONFIG
from utils.autovest_api import post_signal

def main():
    cfg = CONFIG
    assert cfg["live_mode"], "Set live_mode=True in utils/config.py to use live signaling."

    now = datetime.utcnow()
    ticker = cfg["ticker"] or "NSE_SYMBOL"
    position = 0.0
    confidence = 0.0
    price = 0.0

    code, txt = post_signal(
        url=cfg["autovest_webhook_url"],
        api_key=cfg["autovest_api_key"],
        timestamp=now,
        ticker=ticker,
        position=position,
        confidence=confidence,
        price=price,
        strategy="TCN_NSE_v1"
    )
    print("Webhook response:", code, txt[:200])

if __name__ == "__main__":
    main()
