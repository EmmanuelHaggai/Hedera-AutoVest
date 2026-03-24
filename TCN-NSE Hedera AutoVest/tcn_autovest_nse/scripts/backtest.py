import argparse
import pandas as pd

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--results_csv", required=True, help="Path to *_results.csv from train_and_predict")
    args = parser.parse_args()

    df = pd.read_csv(args.results_csv, parse_dates=["Date"]).set_index("Date")
    final_equity = df["equity_curve"].iloc[-1]
    win_rate = (df["y_next_ret"] * df["position"] > 0).mean()
    avg_turnover = (df["position"].diff().abs().fillna(0)).mean()

    print(f"Final equity: {final_equity:.2%}")
    print(f"Directional win rate: {win_rate:.2%}")
    print(f"Average turnover per step: {avg_turnover:.4f}")

if __name__ == "__main__":
    main()
