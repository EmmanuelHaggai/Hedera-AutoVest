"use strict";

require("dotenv").config();
const express = require("express");
const {
  Client,
  PrivateKey,
  AccountCreateTransaction,
  Hbar,
  TransferTransaction,
  TokenCreateTransaction,
  TokenAssociateTransaction,
  TokenType,
  TokenSupplyType,
  TokenMintTransaction,
  TopicCreateTransaction,
  TopicMessageSubmitTransaction,
  PublicKey,
  AccountId,
} = require("@hashgraph/sdk");
const mysql = require("mysql2/promise");

// ---------- Hedera Client ----------
function makeClient() {
  const net = (process.env.HEDERA_NETWORK || "testnet").toLowerCase();
  const client = net === "previewnet" ? Client.forPreviewnet() : Client.forTestnet();
  client.setOperator(process.env.HEDERA_OPERATOR_ID, process.env.HEDERA_OPERATOR_KEY);
  return client;
}

// ---------- DB ----------
let pool;
async function db() {
  if (!pool) {
    pool = await mysql.createPool({
      host: process.env.DB_HOST,
      user: process.env.DB_USER,
      password: process.env.DB_PASS,
      database: process.env.DB_NAME,
      waitForConnections: true,
      connectionLimit: 10,
      namedPlaceholders: true,
    });
  }
  return pool;
}

// ---------- Minimal schema helper (run once) ----------
async function ensureSchema() {
  const conn = await db();
  await conn.query(`
    CREATE TABLE IF NOT EXISTS accounts (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      account_id VARCHAR(64) NOT NULL UNIQUE,
      pub_key TEXT NOT NULL,
      priv_key TEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`);

  await conn.query(`
    CREATE TABLE IF NOT EXISTS tokens (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      token_id VARCHAR(64) NOT NULL UNIQUE,
      name VARCHAR(100) NOT NULL,
      symbol VARCHAR(50) NOT NULL,
      decimals INT NOT NULL,
      type ENUM('FUNGIBLE','NFT') NOT NULL,
      treasury_account_id VARCHAR(64) NOT NULL,
      supply_type ENUM('INFINITE','FINITE') NOT NULL,
      max_supply BIGINT NULL,
      supply_key TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`);

  await conn.query(`
    CREATE TABLE IF NOT EXISTS token_mints (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      token_id VARCHAR(64) NOT NULL,
      amount BIGINT NULL,
      serials_json JSON NULL,
      tx_id VARCHAR(128) NOT NULL,
      status VARCHAR(64) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`);

  await conn.query(`
    CREATE TABLE IF NOT EXISTS transactions (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      tx_id VARCHAR(128) NOT NULL,
      type VARCHAR(64) NOT NULL,
      memo VARCHAR(200) NULL,
      from_acct VARCHAR(64) NULL,
      to_acct VARCHAR(64) NULL,
      token_id VARCHAR(64) NULL,
      hbar_tinybars BIGINT NULL,
      status VARCHAR(64) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`);

  await conn.query(`
    CREATE TABLE IF NOT EXISTS topics (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      topic_id VARCHAR(64) NOT NULL UNIQUE,
      memo VARCHAR(100) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`);

  await conn.query(`
    CREATE TABLE IF NOT EXISTS topic_messages (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      topic_id VARCHAR(64) NOT NULL,
      sequence BIGINT NULL,
      message TEXT NOT NULL,
      tx_id VARCHAR(128) NOT NULL,
      status VARCHAR(64) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`);
}

// ---------- App ----------
const app = express();
app.use(express.json());

// health
app.get("/health", (req, res) => res.json({ ok: true, ts: new Date().toISOString() }));

/**
 * POST /accounts
 * body: { initialHbar?: number }  // HBAR to set as initial balance on creation
 * result: { accountId, publicKey, privateKey, txId, status }
 */
app.post("/accounts", async (req, res) => {
  const client = makeClient();
  try {
    const initialHbar = Number(req.body?.initialHbar ?? 0);
    const priv = PrivateKey.generateED25519();
    const pub = priv.publicKey;

    const tx = await new AccountCreateTransaction()
      .setKey(pub)
      .setInitialBalance(new Hbar(isNaN(initialHbar) ? 0 : initialHbar))
      .execute(client);

    const receipt = await tx.getReceipt(client);
    const newAccountId = receipt.accountId?.toString();
    const status = receipt.status?.toString();

    const conn = await db();
    await conn.execute(
      "INSERT INTO accounts (account_id, pub_key, priv_key) VALUES (:a, :p, :k)",
      { a: newAccountId, p: pub.toStringRaw(), k: priv.toStringRaw() }
    );

    return res.json({
      ok: true,
      accountId: newAccountId,
      publicKey: pub.toStringRaw(),
      privateKey: priv.toStringRaw(),
      txId: tx.transactionId.toString(),
      status,
    });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});

/**
 * POST /fund
 * body: { toAccountId: "0.0.x", hbar: number, memo?: string }
 */
app.post("/fund", async (req, res) => {
  const client = makeClient();
  const { toAccountId, hbar, memo } = req.body || {};
  if (!toAccountId || typeof hbar !== "number") {
    return res.status(400).json({ ok: false, error: "toAccountId and hbar are required" });
  }
  try {
    const tx = await new TransferTransaction()
      .addHbarTransfer(AccountId.fromString(process.env.HEDERA_OPERATOR_ID), new Hbar(-hbar))
      .addHbarTransfer(AccountId.fromString(toAccountId), new Hbar(hbar))
      .setTransactionMemo(memo || "fund testnet account")
      .execute(makeClient());

    const receipt = await tx.getReceipt(client);
    const status = receipt.status?.toString();

    const conn = await db();
    await conn.execute(
      "INSERT INTO transactions (tx_id, type, memo, from_acct, to_acct, hbar_tinybars, status) VALUES (:t, 'HBAR_TRANSFER', :m, :f, :to, :amt, :s)",
      {
        t: tx.transactionId.toString(),
        m: memo || "",
        f: process.env.HEDERA_OPERATOR_ID,
        to: toAccountId,
        amt: new Hbar(hbar).toTinybars().toNumber(),
        s: status,
      }
    );

    return res.json({ ok: true, txId: tx.transactionId.toString(), status });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});

/**
 * POST /tokens
 * body (fungible default):
 * {
 *   name: "My Token",
 *   symbol: "MYT",
 *   decimals: 2,
 *   initialSupply: 0,
 *   treasuryAccountId?: "0.0.x",   // default operator
 *   supplyType?: "INFINITE"|"FINITE",
 *   maxSupply?: number,
 *   type?: "FUNGIBLE"|"NFT"
 * }
 */
app.post("/tokens", async (req, res) => {
  const client = makeClient();
  try {
    const {
      name,
      symbol,
      decimals = 2,
      initialSupply = 0,
      treasuryAccountId,
      supplyType = "INFINITE",
      maxSupply = null,
      type = "FUNGIBLE",
    } = req.body || {};

    if (!name || !symbol) {
      return res.status(400).json({ ok: false, error: "name and symbol are required" });
    }

    const supplyKey = PrivateKey.generateED25519();
    const treasury = treasuryAccountId || process.env.HEDERA_OPERATOR_ID;

    const txBuilder = new TokenCreateTransaction()
      .setTokenName(name)
      .setTokenSymbol(symbol)
      .setTreasuryAccountId(treasury)
      .setSupplyKey(supplyKey)
      .setSupplyType(
        supplyType === "FINITE" ? TokenSupplyType.Finite : TokenSupplyType.Infinite
      );

    if (type === "NFT") {
      txBuilder.setTokenType(TokenType.NonFungibleUnique);
      if (supplyType === "FINITE" && typeof maxSupply === "number") {
        txBuilder.setMaxSupply(maxSupply);
      }
    } else {
      txBuilder.setTokenType(TokenType.FungibleCommon);
      txBuilder.setDecimals(Number(decimals));
      txBuilder.setInitialSupply(Number(initialSupply));
      if (supplyType === "FINITE" && typeof maxSupply === "number") {
        txBuilder.setMaxSupply(maxSupply);
      }
    }

    const tx = await txBuilder.execute(client);
    const receipt = await tx.getReceipt(client);
    const tokenId = receipt.tokenId?.toString();
    const status = receipt.status?.toString();

    const conn = await db();
    await conn.execute(
      `INSERT INTO tokens
       (token_id, name, symbol, decimals, type, treasury_account_id, supply_type, max_supply, supply_key)
       VALUES (:tid, :n, :s, :d, :t, :tre, :st, :ms, :sk)`,
      {
        tid: tokenId,
        n: name,
        s: symbol,
        d: type === "NFT" ? 0 : Number(decimals),
        t: type === "NFT" ? "NFT" : "FUNGIBLE",
        tre: treasury,
        st: supplyType,
        ms: maxSupply,
        sk: supplyKey.toStringRaw(),
      }
    );

    return res.json({
      ok: true,
      tokenId,
      status,
      supplyKey: supplyKey.toStringRaw(), // store securely if you plan to mint later
    });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});

/**
 * POST /tokens/mint
 * body (fungible): { tokenId: "0.0.x", amount: number, supplyKey?: string }
 * body (nft): { tokenId: "0.0.x", metadata: ["base64...", ...], supplyKey?: string }
 */
app.post("/tokens/mint", async (req, res) => {
  const client = makeClient();
  try {
    const { tokenId, amount, metadata, supplyKey } = req.body || {};
    if (!tokenId) return res.status(400).json({ ok: false, error: "tokenId is required" });

    // Load supply key from payload or DB
    let supplyPriv = supplyKey ? PrivateKey.fromString(supplyKey) : null;
    if (!supplyPriv) {
      const conn = await db();
      const [rows] = await conn.execute("SELECT supply_key, type FROM tokens WHERE token_id=:t", { t: tokenId });
      if (!rows.length) return res.status(404).json({ ok: false, error: "token not found in DB" });
      supplyPriv = PrivateKey.fromString(rows[0].supply_key);
    }

    let txBuilder = new TokenMintTransaction().setTokenId(tokenId);

    if (Array.isArray(metadata)) {
      // NFT mint
      txBuilder = txBuilder.setMetadata(metadata.map(m => Buffer.from(m, "base64")));
    } else {
      // Fungible mint
      if (typeof amount !== "number") {
        return res.status(400).json({ ok: false, error: "amount is required for fungible mint" });
      }
      txBuilder = txBuilder.setAmount(amount);
    }

    const tx = await txBuilder.freezeWith(client).sign(supplyPriv).then(signed => signed.execute(client));
    const receipt = await tx.getReceipt(client);
    const status = receipt.status?.toString();
    const serials = receipt.serials ? receipt.serials.map(s => s.low) : null;

    const conn = await db();
    await conn.execute(
      `INSERT INTO token_mints (token_id, amount, serials_json, tx_id, status)
       VALUES (:tid, :amt, :ser, :tx, :st)`,
      {
        tid: tokenId,
        amt: typeof amount === "number" ? amount : null,
        ser: serials ? JSON.stringify(serials) : null,
        tx: tx.transactionId.toString(),
        st: status,
      }
    );

    return res.json({
      ok: true,
      txId: tx.transactionId.toString(),
      status,
      serials: serials || undefined,
    });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});


// POST /tokens/associate
// { accountId, privKey, tokenId }.
// TokenAssociateTransaction 

app.post("/tokens/associate", async (req, res) => {
  try {
    const { accountId, privKey, tokenId } = req.body || {};
    if (!accountId || !privKey || !tokenId) {
      return res.status(400).json({ ok:false, error:"accountId, privKey, tokenId are required" });
    }
    const client = makeClient();
    // Use the user's key to sign the association
    const userKey = PrivateKey.fromString(privKey);
    const tx = await new TokenAssociateTransaction()
      .setAccountId(accountId)
      .setTokenIds([tokenId])
      .freezeWith(client)
      .sign(userKey)
      .then(s => s.execute(client));

    const receipt = await tx.getReceipt(client);
    return res.json({ ok:true, status: receipt.status.toString(), txId: tx.transactionId.toString() });
  } catch (e) {
    return res.status(500).json({ ok:false, error: e.message });
  }
});



/**
 * POST /transfer
 * body: { fromPrivKey?: string, fromAccountId?: string, toAccountId: string, hbar: number, memo?: string }
 * If fromPrivKey/fromAccountId omitted, uses operator.
 */
app.post("/transfer", async (req, res) => {
  const { fromPrivKey, fromAccountId, toAccountId, hbar, memo } = req.body || {};
  if (!toAccountId || typeof hbar !== "number") {
    return res.status(400).json({ ok: false, error: "toAccountId and hbar are required" });
  }
  try {
    const baseClient = makeClient();
    let client = baseClient;
    let from = process.env.HEDERA_OPERATOR_ID;

    if (fromPrivKey && fromAccountId) {
      client = (process.env.HEDERA_NETWORK || "testnet").toLowerCase() === "previewnet"
        ? Client.forPreviewnet()
        : Client.forTestnet();
      client.setOperator(fromAccountId, fromPrivKey);
      from = fromAccountId;
    }

    const tx = await new TransferTransaction()
      .addHbarTransfer(AccountId.fromString(from), new Hbar(-hbar))
      .addHbarTransfer(AccountId.fromString(toAccountId), new Hbar(hbar))
      .setTransactionMemo(memo || "send hbar")
      .execute(client);

    const receipt = await tx.getReceipt(client);
    const status = receipt.status?.toString();

    const conn = await db();
    await conn.execute(
      "INSERT INTO transactions (tx_id, type, memo, from_acct, to_acct, hbar_tinybars, status) VALUES (:t, 'HBAR_TRANSFER', :m, :f, :to, :amt, :s)",
      {
        t: tx.transactionId.toString(),
        m: memo || "",
        f: from,
        to: toAccountId,
        amt: new Hbar(hbar).toTinybars().toNumber(),
        s: status,
      }
    );

    return res.json({ ok: true, txId: tx.transactionId.toString(), status });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});

/**
 * POST /topics
 * body: { memo?: string }
 */
app.post("/topics", async (req, res) => {
  const client = makeClient();
  const { memo } = req.body || {};
  try {
    const tx = await new TopicCreateTransaction()
      .setTopicMemo(memo || "")
      .execute(client);

    const receipt = await tx.getReceipt(client);
    const topicId = receipt.topicId?.toString();
    const status = receipt.status?.toString();

    const conn = await db();
    await conn.execute(
      "INSERT INTO topics (topic_id, memo) VALUES (:tid, :m)",
      { tid: topicId, m: memo || "" }
    );

    return res.json({ ok: true, topicId, status, txId: tx.transactionId.toString() });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});

/**
 * POST /topics/message
 * body: { topicId: "0.0.x", message: "hello world" }
 */
app.post("/topics/message", async (req, res) => {
  const client = makeClient();
  const { topicId, message } = req.body || {};
  if (!topicId || typeof message !== "string") {
    return res.status(400).json({ ok: false, error: "topicId and message are required" });
  }
  try {
    const tx = await new TopicMessageSubmitTransaction()
      .setTopicId(topicId)
      .setMessage(message)
      .execute(client);

    const receipt = await tx.getReceipt(client);
    const status = receipt.status?.toString();
    const sequence = receipt.topicSequenceNumber ? Number(receipt.topicSequenceNumber) : null;

    const conn = await db();
    await conn.execute(
      "INSERT INTO topic_messages (topic_id, sequence, message, tx_id, status) VALUES (:tid, :seq, :msg, :tx, :st)",
      { tid: topicId, seq: sequence, msg: message, tx: tx.transactionId.toString(), st: status }
    );

    return res.json({
      ok: true,
      txId: tx.transactionId.toString(),
      status,
      sequenceNumber: sequence,
    });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
});

// ---------- boot ----------
ensureSchema()
  .then(() => {
    app.listen(process.env.PORT || 5050, () => {
      console.log(`Hedera local API on http://127.0.0.1:${process.env.PORT || 5050}`);
    });
  })
  .catch(err => {
    console.error("Schema init failed:", err);
    process.exit(1);
  });
