CONFIG = {
    "ticker": "None",          # set to None when using CSV
    "csv_path": "data/raw/SCOM.csv",           
    "lookback": 64,
    "epochs": 20,
    "batch_size": 32,
    "learning_rate": 1e-3,
    "train_min_size": 600,
    "val_size": 64,
    "step": 1,
    "use_features": ["Open", "High", "Low", "Close", "Volume"],
    "vol_lookback": 20,
    "deadband": 0.0005,
    "max_exposure": 0.5,
    "slippage_bps": 5,
    "live_mode": False,
    "autovest_webhook_url": "https://autovest.hedera.co.ke/api/signal",
    "autovest_api_key": "SIGU097809HJHO897098IJHIOU09U09OHKGIGYUT6TYDFHSKLJPOE4EDFSFKLJF",
    "device": "auto"              # "auto", "/GPU:0", or "/CPU:0"
}
