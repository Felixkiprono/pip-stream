//+------------------------------------------------------------------+
//| PipStream.mq4                                                    |
//| Copies PipStream signals into this MetaTrader 4 account.         |
//|                                                                  |
//| Install:                                                         |
//|  1. Tools > Options > Expert Advisors: tick "Allow automated     |
//|     trading" and "Allow WebRequest for listed URL", and add      |
//|     https://pip.stream                                           |
//|  2. Drag PipStream onto any one chart and press OK. It shows a   |
//|     6-digit code: type it in PipStream > Brokers. That's it.     |
//|  3. Make sure "AutoTrading" is on in the toolbar. Keep MT4 open. |
//+------------------------------------------------------------------+
#property copyright "PipStream"
#property link      "https://pip.stream"
#property version   "1.10"
#property description "Places, updates and closes the trades from the Telegram channels you copy in PipStream."
#property strict

input string PipStreamKey = "";                    // Optional: leave empty and pair with the code shown on the chart
input string PipStreamUrl = "https://pip.stream";  // Server (add it under Tools > Options > Expert Advisors)
input int    SlippagePoints = 30;                  // Max slippage for market orders, in points

#define PS_MAGIC 770077
#define PS_VERSION "1.10"
#define PS_KEY_FILE "PipStream.key"

string   g_results = "";
string   g_done[];
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
   g_key = StringTrimRight(StringTrimLeft(g_key));
   if(g_key == "")
      g_key = LoadKey();
   if(g_key != "" && StringFind(g_key, "psk_") != 0)
   {
      Alert("PipStream: that key doesn't look right. Leave PipStreamKey empty to pair with a code instead.");
      return(INIT_PARAMETERS_INCORRECT);
   }
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
}

void OnTimer()
{
   if(g_key == "")
      Pair();
   else
      Sync(false);
}

void OnTick()
{
}

//+------------------------------------------------------------------+
//| helpers                                                          |
//+------------------------------------------------------------------+
string Num(const double v, const int digits = 8)
{
   string s = DoubleToString(v, digits);
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

void AddResult(const string id, const bool ok, const int ticket, const double price, const double volume,
               const int code, const string error, const int new_ticket = 0)
{
   string r = "{\"id\":\"" + id + "\",\"ok\":" + (ok ? "true" : "false") +
              ",\"ticket\":\"" + IntegerToString(ticket) + "\",\"new_ticket\":\"" + IntegerToString(new_ticket) +
              "\",\"price\":" + Num(price) + ",\"volume\":" + Num(volume) + ",\"retcode\":" + IntegerToString(code) +
              ",\"error\":\"" + Esc(error) + "\"}";
   g_results = (g_results == "") ? r : g_results + "," + r;
}

string ErrText(const int code)
{
   switch(code)
   {
      case 2:    return("common error");
      case 4:    return("trade server busy");
      case 6:    return("no connection to trade server");
      case 8:    return("too frequent requests");
      case 64:   return("account disabled");
      case 65:   return("invalid account");
      case 128:  return("trade timeout");
      case 129:  return("invalid price");
      case 130:  return("invalid stops (SL/TP too close or wrong side)");
      case 131:  return("invalid trade volume");
      case 132:  return("market is closed");
      case 133:  return("trading is disabled");
      case 134:  return("not enough money");
      case 135:  return("price changed");
      case 136:  return("off quotes");
      case 138:  return("requote");
      case 145:  return("modification denied: too close to market");
      case 146:  return("trade context is busy");
      case 148:  return("too many open orders");
      case 4051: return("invalid parameter");
      case 4106: return("unknown symbol");
      case 4108: return("invalid ticket");
      case 4109: return("trading not allowed: turn on AutoTrading");
      case 4111: return("short positions not allowed");
   }
   return("error " + IntegerToString(code));
}

// After a partial close MT4 gives the remaining lots a NEW ticket whose comment is "from #old".
int FindTicket(const int ticket)
{
   if(OrderSelect(ticket, SELECT_BY_TICKET) && OrderCloseTime() == 0)
      return(ticket);
   string tag = "from #" + IntegerToString(ticket);
   for(int i = OrdersTotal() - 1; i >= 0; i--)
      if(OrderSelect(i, SELECT_BY_POS, MODE_TRADES) && StringFind(OrderComment(), tag) >= 0)
         return(OrderTicket());
   return(-1);
}

//+------------------------------------------------------------------+
//| account state and symbol specs                                   |
//+------------------------------------------------------------------+
string StateJson()
{
   bool allowed = IsTradeAllowed() && (TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) != 0);
   string s = "{\"platform\":\"mt4\",\"ea_version\":\"" + PS_VERSION + "\"" +
              ",\"balance\":" + Num(AccountBalance(), 2) +
              ",\"equity\":" + Num(AccountEquity(), 2) +
              ",\"currency\":\"" + Esc(AccountCurrency()) + "\"" +
              ",\"login\":\"" + IntegerToString(AccountNumber()) + "\"" +
              ",\"server\":\"" + Esc(AccountServer()) + "\"" +
              ",\"company\":\"" + Esc(AccountCompany()) + "\"" +
              ",\"demo\":" + (IsDemo() ? "true" : "false") +
              ",\"trade_allowed\":" + (allowed ? "true" : "false") +
              ",\"positions\":[";
   bool first = true;
   for(int i = 0; i < OrdersTotal() && i < 300; i++)
   {
      if(!OrderSelect(i, SELECT_BY_POS, MODE_TRADES))
         continue;
      if(OrderType() > OP_SELL)
         continue;   // pending orders are not positions
      string p = "{\"ticket\":\"" + IntegerToString(OrderTicket()) + "\"" +
                 ",\"symbol\":\"" + Esc(OrderSymbol()) + "\"" +
                 ",\"side\":\"" + (OrderType() == OP_BUY ? "buy" : "sell") + "\"" +
                 ",\"volume\":" + Num(OrderLots()) +
                 ",\"open_price\":" + Num(OrderOpenPrice()) +
                 ",\"sl\":" + Num(OrderStopLoss()) +
                 ",\"tp\":" + Num(OrderTakeProfit()) +
                 ",\"profit\":" + Num(OrderProfit() + OrderSwap() + OrderCommission(), 2) +
                 ",\"magic\":" + IntegerToString(OrderMagicNumber()) + "}";
      s += (first ? "" : ",") + p;
      first = false;
   }
   return(s + "]}");
}

string SpecsJson()
{
   string s = "{";
   int n = SymbolsTotal(true);
   bool first = true;
   for(int i = 0; i < n && i < 300; i++)
   {
      string sym = SymbolName(i, true);
      double tick_size = MarketInfo(sym, MODE_TICKSIZE);
      double tick_value = MarketInfo(sym, MODE_TICKVALUE);
      if(tick_size <= 0 || tick_value <= 0)
         continue;
      s += (first ? "" : ",") + "\"" + Esc(sym) + "\":{" +
           "\"point\":" + Num(MarketInfo(sym, MODE_POINT), 10) +
           ",\"tick_size\":" + Num(tick_size, 10) +
           ",\"tick_value\":" + Num(tick_value, 10) +
           ",\"contract_size\":" + Num(MarketInfo(sym, MODE_LOTSIZE)) +
           ",\"volume_min\":" + Num(MarketInfo(sym, MODE_MINLOT)) +
           ",\"volume_max\":" + Num(MarketInfo(sym, MODE_MAXLOT)) +
           ",\"volume_step\":" + Num(MarketInfo(sym, MODE_LOTSTEP)) + "}";
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
      if(err == 4060 || err == 4014)
         g_status = "Add " + PipStreamUrl + " under Tools > Options > Expert Advisors > Allow WebRequest";
      else
         g_status = "Can't reach PipStream (error " + IntegerToString(err) + "), retrying";
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
      g_status = "PipStream answered " + IntegerToString(code) + ", retrying";
      Show();
      return;
   }
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
      Sync(true);
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
   k = StringTrimRight(StringTrimLeft(k));
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
   string body = "{\"token\":\"" + g_pair_token + "\",\"platform\":\"mt4\"" +
                 ",\"login\":\"" + IntegerToString(AccountNumber()) + "\"" +
                 ",\"server\":\"" + Esc(AccountServer()) + "\"" +
                 ",\"company\":\"" + Esc(AccountCompany()) + "\"}";
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
   text = StringTrimRight(text);
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

void Show()
{
   bool allowed = IsTradeAllowed() && (TerminalInfoInteger(TERMINAL_TRADE_ALLOWED) != 0);
   string s = "PipStream " + PS_VERSION + ": " + g_status;
   if(!allowed)
      s += "\nAutoTrading is OFF: turn it on in the toolbar so trades can be placed";
   if(g_last_ok > 0)
      s += "\nLast check-in " + TimeToString(g_last_ok, TIME_SECONDS);
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
   string id = f[1];
   string action = f[2];
   string symbol = f[3];
   string side = f[4];
   string otype = f[5];
   string comment = f[11];
   double volume = StringToDouble(f[6]);
   double price = StringToDouble(f[7]);
   double sl = StringToDouble(f[8]);
   double tp = StringToDouble(f[9]);
   int    ticket = (int)StringToInteger(f[10]);
   if(id == "" || IsDone(id))
      return;
   MarkDone(id);
   RefreshRates();

   if(action == "open")
   {
      if(!SymbolSelect(symbol, true) || MarketInfo(symbol, MODE_POINT) <= 0)
      {
         AddResult(id, false, 0, 0, 0, 4106, "symbol " + symbol + " not available on this account");
         return;
      }
      int digits = (int)MarketInfo(symbol, MODE_DIGITS);
      if(sl > 0) sl = NormalizeDouble(sl, digits);
      if(tp > 0) tp = NormalizeDouble(tp, digits);
      int cmd;
      double at;
      if(otype == "limit")
      {
         cmd = (side == "buy") ? OP_BUYLIMIT : OP_SELLLIMIT;
         at = NormalizeDouble(price, digits);
      }
      else if(otype == "stop")
      {
         cmd = (side == "buy") ? OP_BUYSTOP : OP_SELLSTOP;
         at = NormalizeDouble(price, digits);
      }
      else
      {
         cmd = (side == "buy") ? OP_BUY : OP_SELL;
         at = (side == "buy") ? MarketInfo(symbol, MODE_ASK) : MarketInfo(symbol, MODE_BID);
      }
      ResetLastError();
      // Send without stops first, then add them: works on brokers that reject SL/TP on market orders
      int t = OrderSend(symbol, cmd, volume, at, SlippagePoints, 0, 0, "PipStream " + comment, PS_MAGIC, 0, clrNONE);
      if(t < 0)
      {
         int e = GetLastError();
         AddResult(id, false, 0, 0, 0, e, ErrText(e));
         return;
      }
      double fill = at;
      double lots = volume;
      if(OrderSelect(t, SELECT_BY_TICKET))
      {
         fill = OrderOpenPrice();
         lots = OrderLots();
         if(sl > 0 || tp > 0)
         {
            if(!OrderModify(t, OrderOpenPrice(), sl, tp, 0, clrNONE))
            {
               int e = GetLastError();
               // the trade is open; report it, with the reason the stops were not set
               AddResult(id, true, t, fill, lots, e, "opened, but SL/TP not set: " + ErrText(e));
               return;
            }
         }
      }
      AddResult(id, true, t, fill, lots, 0, "");
      return;
   }
   if(action == "ping")
   {
      AddResult(id, true, 0, 0, 0, 0, "");
      return;
   }
   if(action == "modify")
   {
      int t = FindTicket(ticket);
      if(t < 0 || !OrderSelect(t, SELECT_BY_TICKET))
      {
         AddResult(id, false, ticket, 0, 0, 4108, "position " + IntegerToString(ticket) + " not found");
         return;
      }
      int digits = (int)MarketInfo(OrderSymbol(), MODE_DIGITS);
      double new_sl = (sl > 0) ? NormalizeDouble(sl, digits) : OrderStopLoss();
      double new_tp = (tp > 0) ? NormalizeDouble(tp, digits) : OrderTakeProfit();
      if(MathAbs(new_sl - OrderStopLoss()) < Point / 2 && MathAbs(new_tp - OrderTakeProfit()) < Point / 2)
      {
         AddResult(id, true, t, 0, 0, 0, "", t != ticket ? t : 0);   // nothing to change
         return;
      }
      ResetLastError();
      if(OrderModify(t, OrderOpenPrice(), new_sl, new_tp, 0, clrNONE))
         AddResult(id, true, t, 0, 0, 0, "", t != ticket ? t : 0);
      else
      {
         int e = GetLastError();
         AddResult(id, false, t, 0, 0, e, ErrText(e));
      }
      return;
   }
   if(action == "close")
   {
      int t = FindTicket(ticket);
      if(t < 0 || !OrderSelect(t, SELECT_BY_TICKET))
      {
         AddResult(id, false, ticket, 0, 0, 4108, "position " + IntegerToString(ticket) + " not found (already closed?)");
         return;
      }
      double have = OrderLots();
      double step = MarketInfo(OrderSymbol(), MODE_LOTSTEP);
      double lots = (volume > 0 && volume < have) ? volume : have;
      if(step > 0)
         lots = MathMax(MarketInfo(OrderSymbol(), MODE_MINLOT), MathFloor(lots / step + 0.0000001) * step);
      double at = (OrderType() == OP_BUY) ? MarketInfo(OrderSymbol(), MODE_BID) : MarketInfo(OrderSymbol(), MODE_ASK);
      ResetLastError();
      if(OrderClose(t, lots, at, SlippagePoints, clrNONE))
      {
         int rest = (lots < have) ? FindTicket(t) : 0;
         double closed_at = at;
         if(OrderSelect(t, SELECT_BY_TICKET, MODE_HISTORY))
            closed_at = OrderClosePrice();
         AddResult(id, true, t, closed_at, lots, 0, "", rest > 0 ? rest : 0);
      }
      else
      {
         int e = GetLastError();
         AddResult(id, false, t, 0, 0, e, ErrText(e));
      }
      return;
   }
   AddResult(id, false, 0, 0, 0, 0, "unknown action " + action);
}
//+------------------------------------------------------------------+
