//+------------------------------------------------------------------+
//| PipStream.mq5                                                    |
//| Copies PipStream signals into this MetaTrader 5 account.         |
//|                                                                  |
//| Install:                                                         |
//|  1. Tools > Options > Expert Advisors: tick "Allow WebRequest    |
//|     for listed URL" and add  https://pip.stream                  |
//|  2. Drag PipStream onto any one chart and press OK. It shows a   |
//|     6-digit code: type it in PipStream > Brokers. That's it.     |
//|  3. Turn on "Algo Trading" in the toolbar. Keep MT5 running.     |
//+------------------------------------------------------------------+
#property copyright "PipStream"
#property link      "https://pip.stream"
#property version   "1.10"
#property description "Places, updates and closes the trades from the Telegram channels you copy in PipStream."

#include <Trade\Trade.mqh>

input string PipStreamKey = "";                    // Optional: leave empty and pair with the code shown on the chart
input string PipStreamUrl = "http://127.0.0.1:8000";  // Server (add it under Tools > Options > Expert Advisors)
input int    SlippagePoints = 30;                  // Max slippage for market orders, in points

#define PS_MAGIC 770077
#define PS_VERSION "1.11"
#define PS_KEY_FILE "PipStream.key"

CTrade   g_trade;
string   g_results = "";        // JSON objects waiting to be reported
string   g_done[];              // command ids already executed (never run twice)
int      g_poll_ms = 1000;
datetime g_last_state = 0;
datetime g_last_specs = 0;
datetime g_last_ok = 0;
string   g_status = "starting";
string   g_key = "";            // from the input, or saved after pairing
string   g_pair_token = "";
string   g_pair_code = "";

//+------------------------------------------------------------------+
int OnInit()
{
   g_key = PipStreamKey;
   StringTrimLeft(g_key);
   StringTrimRight(g_key);
   if(g_key == "")
      g_key = LoadKey();
   if(g_key != "" && StringFind(g_key, "psk_") != 0)
   {
      Alert("PipStream: that key doesn't look right. Leave PipStreamKey empty to pair with a code instead.");
      return(INIT_PARAMETERS_INCORRECT);
   }
   g_trade.SetExpertMagicNumber(PS_MAGIC);
   g_trade.SetDeviationInPoints(SlippagePoints);
   g_trade.SetAsyncMode(false);
   EventSetMillisecondTimer(g_poll_ms);
   if(g_key == "")
      Pair();
   else
      Sync(true);
   return(INIT_SUCCEEDED);
}

void OnDeinit(const int reason)
{
   EventKillTimer();
   Comment("");
   ObjectDelete(0, PS_CODE_LABEL);
}

void OnTimer()
{
   if(g_key == "")
      Pair();
   else
      Sync(false);
}

//+------------------------------------------------------------------+
//| helpers                                                          |
//+------------------------------------------------------------------+
string Num(const double v, const int digits = 8)
{
   string s = DoubleToString(v, digits);
   // trim trailing zeros to keep the payload small
   if(StringFind(s, ".") >= 0)
   {
      while(StringLen(s) > 1 && StringGetCharacter(s, StringLen(s) - 1) == '0')
         s = StringSubstr(s, 0, StringLen(s) - 1);
      if(StringGetCharacter(s, StringLen(s) - 1) == '.')
         s = StringSubstr(s, 0, StringLen(s) - 1);
   }
   return(s);
}

string Esc(string s)
{
   StringReplace(s, "\\", "/");
   StringReplace(s, "\"", "'");
   StringReplace(s, "\n", " ");
   StringReplace(s, "\r", " ");
   return(s);
}

bool IsDone(const string id)
{
   for(int i = 0; i < ArraySize(g_done); i++)
      if(g_done[i] == id)
         return(true);
   return(false);
}

void MarkDone(const string id)
{
   int n = ArraySize(g_done);
   if(n >= 300)
   {
      for(int i = 1; i < n; i++)
         g_done[i - 1] = g_done[i];
      n--;
      ArrayResize(g_done, n);
   }
   ArrayResize(g_done, n + 1);
   g_done[n] = id;
}

void AddResult(const string id, const bool ok, const ulong ticket, const double price, const double volume,
               const int retcode, const string error)
{
   string r = "{\"id\":\"" + id + "\",\"ok\":" + (ok ? "true" : "false") +
              ",\"ticket\":\"" + (string)ticket + "\",\"price\":" + Num(price) +
              ",\"volume\":" + Num(volume) + ",\"retcode\":" + (string)retcode +
              ",\"error\":\"" + Esc(error) + "\"}";
   g_results = (g_results == "") ? r : g_results + "," + r;
}

bool RetOk(const uint rc)
{
   return(rc == TRADE_RETCODE_DONE || rc == TRADE_RETCODE_PLACED || rc == TRADE_RETCODE_DONE_PARTIAL);
}

//+------------------------------------------------------------------+
//| account state and symbol specs                                   |
//+------------------------------------------------------------------+
string StateJson()
{
   bool allowed = (TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) != 0) && (MQLInfoInteger(MQL_TRADE_ALLOWED) != 0);
   string s = "{\"platform\":\"mt5\",\"ea_version\":\"" + PS_VERSION + "\"" +
              ",\"balance\":" + Num(AccountInfoDouble(ACCOUNT_BALANCE), 2) +
              ",\"equity\":" + Num(AccountInfoDouble(ACCOUNT_EQUITY), 2) +
              ",\"currency\":\"" + Esc(AccountInfoString(ACCOUNT_CURRENCY)) + "\"" +
              ",\"login\":\"" + (string)AccountInfoInteger(ACCOUNT_LOGIN) + "\"" +
              ",\"server\":\"" + Esc(AccountInfoString(ACCOUNT_SERVER)) + "\"" +
              ",\"company\":\"" + Esc(AccountInfoString(ACCOUNT_COMPANY)) + "\"" +
              ",\"demo\":" + (AccountInfoInteger(ACCOUNT_TRADE_MODE) == ACCOUNT_TRADE_MODE_DEMO ? "true" : "false") +
              ",\"trade_allowed\":" + (allowed ? "true" : "false") +
              ",\"positions\":[";
   int n = PositionsTotal();
   bool first = true;
   for(int i = 0; i < n && i < 300; i++)
   {
      ulong t = PositionGetTicket(i);
      if(t == 0)
         continue;
      string p = "{\"ticket\":\"" + (string)t + "\"" +
                 ",\"symbol\":\"" + Esc(PositionGetString(POSITION_SYMBOL)) + "\"" +
                 ",\"side\":\"" + (PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY ? "buy" : "sell") + "\"" +
                 ",\"volume\":" + Num(PositionGetDouble(POSITION_VOLUME)) +
                 ",\"open_price\":" + Num(PositionGetDouble(POSITION_PRICE_OPEN)) +
                 ",\"sl\":" + Num(PositionGetDouble(POSITION_SL)) +
                 ",\"tp\":" + Num(PositionGetDouble(POSITION_TP)) +
                 ",\"profit\":" + Num(PositionGetDouble(POSITION_PROFIT), 2) +
                 ",\"magic\":" + (string)PositionGetInteger(POSITION_MAGIC) + "}";
      s += (first ? "" : ",") + p;
      first = false;
   }
   return(s + "]}");
}

string SpecsJson()
{
   string s = "{";
   int n = SymbolsTotal(true);   // Market Watch only
   bool first = true;
   for(int i = 0; i < n && i < 300; i++)
   {
      string sym = SymbolName(i, true);
      double tick_size = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_SIZE);
      double tick_value = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_VALUE);
      if(tick_size <= 0 || tick_value <= 0)
         continue;
      s += (first ? "" : ",") + "\"" + Esc(sym) + "\":{" +
           "\"point\":" + Num(SymbolInfoDouble(sym, SYMBOL_POINT), 10) +
           ",\"tick_size\":" + Num(tick_size, 10) +
           ",\"tick_value\":" + Num(tick_value, 10) +
           ",\"contract_size\":" + Num(SymbolInfoDouble(sym, SYMBOL_TRADE_CONTRACT_SIZE)) +
           ",\"volume_min\":" + Num(SymbolInfoDouble(sym, SYMBOL_VOLUME_MIN)) +
           ",\"volume_max\":" + Num(SymbolInfoDouble(sym, SYMBOL_VOLUME_MAX)) +
           ",\"volume_step\":" + Num(SymbolInfoDouble(sym, SYMBOL_VOLUME_STEP)) + "}";
      first = false;
   }
   return(s + "}");
}

//+------------------------------------------------------------------+
//| talk to PipStream                                                |
//+------------------------------------------------------------------+
void Sync(const bool force_state)
{
   bool send_state = force_state || (TimeLocal() - g_last_state >= 3);
   bool send_specs = force_state || (TimeLocal() - g_last_specs >= 60);
   string body = "{\"results\":[" + g_results + "]";
   if(send_state)
      body += ",\"state\":" + StateJson();
   if(send_specs)
      body += ",\"specs\":" + SpecsJson();
   body += "}";

   char   data[];
   char   reply[];
   string reply_headers;
   int len = StringToCharArray(body, data, 0, WHOLE_ARRAY, CP_UTF8) - 1;
   if(len < 0)
      len = 0;
   ArrayResize(data, len);
   string headers = "Content-Type: application/json\r\nX-PipStream-Key: " + g_key + "\r\n";
   string url = PipStreamUrl + "/ea/v1/sync";

   ResetLastError();
   int code = WebRequest("POST", url, headers, 5000, data, reply, reply_headers);
   if(code == -1)
   {
      int err = GetLastError();
      if(err == 4014)
         g_status = "Add " + PipStreamUrl + " under Tools > Options > Expert Advisors > Allow WebRequest";
      else
         g_status = "Can't reach PipStream (error " + (string)err + "), retrying";
      Show();
      return;
   }
   if(code == 401)
   {
      if(PipStreamKey == "")
      {
         // the saved key was replaced or removed in PipStream: pair again
         SaveKey("");
         g_key = "";
         g_pair_token = "";
         g_status = "Not linked any more. Getting a new pairing code";
      }
      else
         g_status = "Key not accepted. Make a new one in PipStream > Brokers, or leave PipStreamKey empty to pair";
      Show();
      return;
   }
   if(code != 200)
   {
      g_status = "PipStream answered " + (string)code + ", retrying";
      Show();
      return;
   }
   // reported: clear what we sent
   g_results = "";
   if(send_state)
      g_last_state = TimeLocal();
   if(send_specs)
      g_last_specs = TimeLocal();
   g_last_ok = TimeLocal();
   g_status = "Connected";

   string text = CharArrayToString(reply, 0, WHOLE_ARRAY, CP_UTF8);
   string lines[];
   int n = StringSplit(text, '\n', lines);
   bool ran = false;
   for(int i = 0; i < n; i++)
   {
      if(StringFind(lines[i], "C|") != 0)
         continue;
      Execute(lines[i]);
      ran = true;
   }
   Show();
   if(ran)
      Sync(true);   // report fills right away instead of on the next tick of the timer
}

//+------------------------------------------------------------------+
//| pairing: no key -> show a 6-digit code, the customer types it    |
//| in PipStream, we receive our key and keep it in MQL Files        |
//+------------------------------------------------------------------+
string LoadKey()
{
   int h = FileOpen(PS_KEY_FILE, FILE_READ | FILE_TXT | FILE_ANSI);
   if(h == INVALID_HANDLE)
      return("");
   string k = FileReadString(h);
   FileClose(h);
   StringTrimLeft(k);
   StringTrimRight(k);
   return(StringFind(k, "psk_") == 0 ? k : "");
}

void SaveKey(const string k)
{
   if(k == "")
   {
      FileDelete(PS_KEY_FILE);
      return;
   }
   int h = FileOpen(PS_KEY_FILE, FILE_WRITE | FILE_TXT | FILE_ANSI);
   if(h == INVALID_HANDLE)
      return;
   FileWriteString(h, k);
   FileClose(h);
}

void Pair()
{
   string body = "{\"token\":\"" + g_pair_token + "\",\"platform\":\"mt5\"" +
                 ",\"login\":\"" + (string)AccountInfoInteger(ACCOUNT_LOGIN) + "\"" +
                 ",\"server\":\"" + Esc(AccountInfoString(ACCOUNT_SERVER)) + "\"" +
                 ",\"company\":\"" + Esc(AccountInfoString(ACCOUNT_COMPANY)) + "\"}";
   char   data[];
   char   reply[];
   string reply_headers;
   int len = StringToCharArray(body, data, 0, WHOLE_ARRAY, CP_UTF8) - 1;
   if(len < 0)
      len = 0;
   ArrayResize(data, len);
   ResetLastError();
   int code = WebRequest("POST", PipStreamUrl + "/ea/v1/pair", "Content-Type: application/json\r\n", 5000, data, reply, reply_headers);
   if(code != 200)
   {
      int err = GetLastError();
      if(code == -1 && (err == 4014 || err == 4060))
         g_status = "Step 1: add " + PipStreamUrl + " under Tools > Options > Expert Advisors > Allow WebRequest";
      else if(code == -1)
         g_status = "Can't reach PipStream (error " + IntegerToString(err) + "), retrying";
      else
         g_status = "PipStream answered " + IntegerToString(code) + ", retrying";
      Show();
      return;
   }
   string text = CharArrayToString(reply, 0, WHOLE_ARRAY, CP_UTF8);
   StringTrimRight(text);
   string f[];
   int n = StringSplit(text, '|', f);
   if(n >= 3 && f[0] == "PAIR")
   {
      g_pair_token = f[1];
      g_pair_code = f[2];
   }
   else if(n >= 2 && f[0] == "WAIT")
      g_pair_code = f[1];
   else if(n >= 2 && f[0] == "KEY" && StringFind(f[1], "psk_") == 0)
   {
      g_key = f[1];
      SaveKey(g_key);
      g_pair_token = "";
      g_pair_code = "";
      g_status = "Linked";
      Sync(true);
      return;
   }
   if(g_pair_code != "")
      g_status = "Enter this code in PipStream > Brokers:  " + StringSubstr(g_pair_code, 0, 3) + " " + StringSubstr(g_pair_code, 3);
   Show();
}

#define PS_CODE_LABEL "PipStreamPairCode"
void ShowCode(const string text)
{
   if(text == "")
   {
      ObjectDelete(0, PS_CODE_LABEL);
      return;
   }
   if(ObjectFind(0, PS_CODE_LABEL) < 0)
   {
      ObjectCreate(0, PS_CODE_LABEL, OBJ_LABEL, 0, 0, 0);
      ObjectSetInteger(0, PS_CODE_LABEL, OBJPROP_CORNER, CORNER_LEFT_UPPER);
      ObjectSetInteger(0, PS_CODE_LABEL, OBJPROP_XDISTANCE, 12);
      ObjectSetInteger(0, PS_CODE_LABEL, OBJPROP_YDISTANCE, 70);
      ObjectSetInteger(0, PS_CODE_LABEL, OBJPROP_FONTSIZE, 28);
      ObjectSetString(0, PS_CODE_LABEL, OBJPROP_FONT, "Arial Bold");
      ObjectSetInteger(0, PS_CODE_LABEL, OBJPROP_COLOR, clrGold);
      ObjectSetInteger(0, PS_CODE_LABEL, OBJPROP_SELECTABLE, false);
   }
   ObjectSetString(0, PS_CODE_LABEL, OBJPROP_TEXT, text);
   ChartRedraw(0);
}

void Show()
{
   bool allowed = (TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) != 0) && (MQLInfoInteger(MQL_TRADE_ALLOWED) != 0);
   string s = "PipStream " + PS_VERSION + ": " + g_status;
   if(!allowed)
      s += "\nAlgo Trading is OFF: turn it on in the toolbar so trades can be placed";
   if(g_last_ok > 0)
      s += "\nLast check-in " + TimeToString(g_last_ok, TIME_SECONDS);
   bool pairing = (g_pair_code != "" && StringFind(g_status, "Enter this code") == 0);
   ShowCode(pairing ? "PipStream code: " + StringSubstr(g_pair_code, 0, 3) + " " + StringSubstr(g_pair_code, 3) : "");
   Comment(s);
}

//+------------------------------------------------------------------+
//| C|id|action|symbol|side|order_type|volume|price|sl|tp|ticket|comment
//+------------------------------------------------------------------+
void Execute(const string line)
{
   string f[];
   if(StringSplit(line, '|', f) < 12)
      return;
   string id = f[1], action = f[2], symbol = f[3], side = f[4], otype = f[5], comment = f[11];
   double volume = StringToDouble(f[6]), price = StringToDouble(f[7]);
   double sl = StringToDouble(f[8]), tp = StringToDouble(f[9]);
   ulong  ticket = (ulong)StringToInteger(f[10]);
   if(id == "" || IsDone(id))
      return;
   MarkDone(id);

   if(action == "open")
   {
      if(!SymbolSelect(symbol, true))
      {
         AddResult(id, false, 0, 0, 0, 0, "symbol " + symbol + " not available on this account");
         return;
      }
      g_trade.SetTypeFillingBySymbol(symbol);
      int digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
      if(sl > 0) sl = NormalizeDouble(sl, digits);
      if(tp > 0) tp = NormalizeDouble(tp, digits);
      if(price > 0) price = NormalizeDouble(price, digits);
      string c = "PipStream " + comment;
      bool sent = false;
      if(otype == "limit")
         sent = (side == "buy") ? g_trade.BuyLimit(volume, price, symbol, sl, tp, ORDER_TIME_GTC, 0, c)
                                : g_trade.SellLimit(volume, price, symbol, sl, tp, ORDER_TIME_GTC, 0, c);
      else if(otype == "stop")
         sent = (side == "buy") ? g_trade.BuyStop(volume, price, symbol, sl, tp, ORDER_TIME_GTC, 0, c)
                                : g_trade.SellStop(volume, price, symbol, sl, tp, ORDER_TIME_GTC, 0, c);
      else
         sent = (side == "buy") ? g_trade.Buy(volume, symbol, 0, sl, tp, c)
                                : g_trade.Sell(volume, symbol, 0, sl, tp, c);
      uint rc = g_trade.ResultRetcode();
      bool ok = sent && RetOk(rc);
      // for a market order the position id equals the opening order ticket
      AddResult(id, ok, g_trade.ResultOrder(), g_trade.ResultPrice(), g_trade.ResultVolume(), (int)rc,
                ok ? "" : g_trade.ResultRetcodeDescription());
      return;
   }
   if(action == "ping")
   {
      AddResult(id, true, 0, 0, 0, 0, "");
      return;
   }
   if(action == "modify")
   {
      if(!PositionSelectByTicket(ticket))
      {
         AddResult(id, false, ticket, 0, 0, 0, "position " + (string)ticket + " not found");
         return;
      }
      int digits = (int)SymbolInfoInteger(PositionGetString(POSITION_SYMBOL), SYMBOL_DIGITS);
      double new_sl = (sl > 0) ? NormalizeDouble(sl, digits) : PositionGetDouble(POSITION_SL);
      double new_tp = (tp > 0) ? NormalizeDouble(tp, digits) : PositionGetDouble(POSITION_TP);
      bool sent = g_trade.PositionModify(ticket, new_sl, new_tp);
      uint rc = g_trade.ResultRetcode();
      bool ok = sent && (RetOk(rc) || rc == TRADE_RETCODE_NO_CHANGES);
      AddResult(id, ok, ticket, 0, 0, (int)rc, ok ? "" : g_trade.ResultRetcodeDescription());
      return;
   }
   if(action == "close")
   {
      if(!PositionSelectByTicket(ticket))
      {
         AddResult(id, false, ticket, 0, 0, 0, "position " + (string)ticket + " not found (already closed?)");
         return;
      }
      double have = PositionGetDouble(POSITION_VOLUME);
      g_trade.SetTypeFillingBySymbol(PositionGetString(POSITION_SYMBOL));
      bool sent;
      if(volume > 0 && volume < have)
         sent = g_trade.PositionClosePartial(ticket, volume);
      else
      {
         sent = g_trade.PositionClose(ticket);
         volume = have;
      }
      uint rc = g_trade.ResultRetcode();
      bool ok = sent && RetOk(rc);
      AddResult(id, ok, ticket, g_trade.ResultPrice(), volume, (int)rc, ok ? "" : g_trade.ResultRetcodeDescription());
      return;
   }
   AddResult(id, false, 0, 0, 0, 0, "unknown action " + action);
}
//+------------------------------------------------------------------+
