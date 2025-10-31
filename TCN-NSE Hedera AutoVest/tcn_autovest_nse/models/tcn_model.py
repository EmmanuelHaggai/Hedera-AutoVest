import tensorflow as tf
from tensorflow.keras import Sequential
from tensorflow.keras.layers import Conv1D, Dropout, Dense, Flatten, LayerNormalization
from tensorflow.keras.optimizers import Adam

def build_tcn(input_shape, learning_rate=1e-3, dilations=(1, 2, 4, 8), filters=64, dropout=0.2):
    model = Sequential()
    for i, d in enumerate(dilations):
        model.add(Conv1D(
            filters=filters,
            kernel_size=3,
            padding="causal",
            activation="relu",
            dilation_rate=d,
            input_shape=input_shape if i == 0 else None
        ))
        model.add(Dropout(dropout))
        model.add(LayerNormalization())
    model.add(Flatten())
    model.add(Dense(64, activation="relu"))
    model.add(Dropout(dropout))
    model.add(Dense(1, activation="linear"))
    model.compile(optimizer=Adam(learning_rate=learning_rate), loss="mse")
    return model
