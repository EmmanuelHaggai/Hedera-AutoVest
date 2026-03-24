# TCN NSE Hedera AutoVest

A temporal convolutional network that predicts next-bar returns for Nairobi Stock Exchange symbols. It sizes positions with a risk map and can post live signals to Hedera AutoVest.

## Quick start

1. Create a virtual environment and install dependencies.
2. Put your CSV in `data/raw` with columns `Date,Open,High,Low,Close,Volume`, or set a Yahoo-compatible ticker in `utils/config.py`.
3. Edit `utils/config.py` to set `ticker` or `csv_path`, plus lookback and training settings.
4. Run:
```bash
python scripts/train_and_predict.py
