import numpy as np

def risk_map_to_position(pred_ret: float, est_vol: float, deadband: float, max_exposure: float) -> float:
    if abs(pred_ret) < deadband:
        return 0.0
    vol = max(est_vol, 1e-6)
    pos = np.tanh(pred_ret / vol)
    return float(np.clip(pos, -max_exposure, max_exposure))

def apply_slippage(ret: float, trade_turnover: float, slippage_bps: float) -> float:
    cost = (slippage_bps / 10000.0) * trade_turnover
    return ret - cost

def confidence_score(pred_ret: float, est_vol: float) -> float:
    vol = max(est_vol, 1e-6)
    return float(np.clip(abs(pred_ret) / vol, 0.0, 1.0))
