<?php
declare(strict_types=1);
require_once __DIR__ . '/SecurityService.php';
final class CryptoService {
    /** Live rates are refreshed at most once per minute. */
    private const RATE_TTL_SECONDS = 60;
    /** CoinGecko simple-price endpoint (no API key required). */
    private const PRICE_URL = 'https://api.coingecko.com/api/v3/simple/price?ids=%s&vs_currencies=usd';

    public function __construct(private PDO $db){}
    /** Timestamp of the last live-rate fetch attempt (negative cache so an unreachable feed is retried at most once per minute). */
    private static int $lastRateAttempt = 0;

    public function assets():array{return $this->db->query('SELECT * FROM crypto_assets WHERE active=1 ORDER BY FIELD(symbol,"BTC","ETH","USDT","USDC","XRP")')->fetchAll();}

    public function ensureWallets(int $userId):void{$s=$this->db->query('SELECT id,symbol FROM crypto_assets WHERE active=1');foreach($s->fetchAll() as $a){$q=$this->db->prepare('SELECT id FROM crypto_wallets WHERE user_id=? AND asset_id=?');$q->execute([$userId,$a['id']]);if(!$q->fetchColumn()){$address='cs'.strtolower($a['symbol']).'_'.$userId.'_'.bin2hex(random_bytes(12));$this->db->prepare('INSERT INTO crypto_wallets(user_id,asset_id,address,balance) VALUES(?,?,?,0)')->execute([$userId,$a['id'],$address]);}}}

    public function wallets(int $userId):array{$this->ensureWallets($userId);$s=$this->db->prepare('SELECT w.*,a.symbol,a.name,a.decimals,a.usd_rate,a.live_usd_rate,a.live_rate_at FROM crypto_wallets w JOIN crypto_assets a ON a.id=w.asset_id WHERE w.user_id=? AND a.active=1 ORDER BY FIELD(a.symbol,"BTC","ETH","USDT","USDC","XRP")');$s->execute([$userId]);$rows=$s->fetchAll();foreach($rows as &$r){$eff=$this->effectiveRate($r);$r['eff_rate']=$eff['rate'];$r['rate_source']=$eff['source'];}return $rows;}

    public function wallet(int $userId,string $symbol):array{$this->ensureWallets($userId);$s=$this->db->prepare('SELECT w.*,a.symbol,a.name,a.decimals,a.usd_rate,a.live_usd_rate,a.live_rate_at FROM crypto_wallets w JOIN crypto_assets a ON a.id=w.asset_id WHERE w.user_id=? AND a.symbol=? LIMIT 1');$s->execute([$userId,strtoupper($symbol)]);$w=$s->fetch();if(!$w)throw new RuntimeException('Crypto wallet not found.');$eff=$this->effectiveRate($w);$w['eff_rate']=$eff['rate'];$w['rate_source']=$eff['source'];return $w;}

    /**
     * Effective USD rate for an asset row: cached live price when fresh,
     * otherwise the bank reference rate. Attempts a live refresh when stale.
     * Returns ['rate'=>float,'source'=>'live'|'reference'].
     */
    public function effectiveRate(array $asset):array{
        if($this->rowIsLive($asset))return ['rate'=>(float)$asset['live_usd_rate'],'source'=>'live'];
        $this->refreshLiveRates();
        // Re-read the asset row: the cache was just refreshed.
        $stmt=$this->db->prepare('SELECT * FROM crypto_assets WHERE id=? LIMIT 1');
        $stmt->execute([(int)$asset['id']]);
        $fresh=$stmt->fetch();
        if($fresh && $this->rowIsLive($fresh))return ['rate'=>(float)$fresh['live_usd_rate'],'source'=>'live'];
        if((float)($asset['usd_rate']??0)<=0)throw new RuntimeException('No rate available for this asset.');
        return ['rate'=>(float)$asset['usd_rate'],'source'=>'reference'];
    }

    /** True when the row carries a fresh cached live price. */
    private function rowIsLive(array $a):bool{
        $liveAt=$a['live_rate_at']??null;
        return $liveAt!==null && $a['live_usd_rate']!==null && (time()-strtotime((string)$liveAt))<self::RATE_TTL_SECONDS && (float)$a['live_usd_rate']>0;
    }

    /** Rate for a single symbol (fresh look-up, used by the trade page). */
    public function rate(string $symbol):array{$s=$this->db->prepare('SELECT * FROM crypto_assets WHERE symbol=? AND active=1 LIMIT 1');$s->execute([strtoupper(trim($symbol))]);$a=$s->fetch();if(!$a)throw new RuntimeException('Crypto asset not found.');return $this->effectiveRate($a);}

    /**
     * Fetch live USD prices (CoinGecko simple price) for all mapped assets and
     * cache them. Short timeout; silently keeps reference rates when the
     * gateway is unreachable.
     */
    public function refreshLiveRates():void{
        if((time()-self::$lastRateAttempt)<self::RATE_TTL_SECONDS)return; // negative cache (failed or fresh fetch)
        self::$lastRateAttempt=time();
        try{
            $assets=$this->db->query('SELECT id,symbol,coingecko_id FROM crypto_assets WHERE active=1 AND coingecko_id IS NOT NULL')->fetchAll();
            if(!$assets)return;
            $byId=[];$ids=[];
            foreach($assets as $a){$ids[]=$a['coingecko_id'];$byId[$a['coingecko_id']]=$a;}
            $url=sprintf(self::PRICE_URL,rawurlencode(implode(',',$ids)));
            $body=$this->httpGet($url);
            if($body===null)return;
            $prices=json_decode($body,true);
            if(!is_array($prices))return;
            $upd=$this->db->prepare('UPDATE crypto_assets SET live_usd_rate=?,live_rate_at=CURRENT_TIMESTAMP WHERE id=?');
            foreach($byId as $cgId=>$a){
                $usd=$prices[$cgId]['usd']??null;
                if($usd!==null && is_finite((float)$usd) && (float)$usd>0)$upd->execute([number_format((float)$usd,8,'.',''),$a['id']]);
            }
        }catch(Throwable $e){
            // keep reference rates on any failure
        }
    }

    /** Small tolerant HTTP GET (allow_url_fopen first, cURL fallback). */
    private function httpGet(string $url):?string{
        $ctx=stream_context_create(['http'=>['timeout'=>4,'header'=>"User-Agent: CommServe/1.0\r\n"]]);
        $body=@file_get_contents($url,false,$ctx);
        if(is_string($body) && $body!=='')return $body;
        if(function_exists('curl_init')){
            try{
                $ch=curl_init($url);
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>4,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_USERAGENT=>'CommServe/1.0']);
                $out=curl_exec($ch);
                $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                if(is_string($out) && $code>=200 && $code<300 && $out!=='')return $out;
            }catch(Throwable $e){/* fall through */}
        }
        return null;
    }

    /**
     * Buy crypto with fiat account balance at the current rate.
     * Debits the ledger-backed fiat account and credits the crypto wallet in
     * one database transaction. Returns trade details.
     */
    public function buy(int $userId,int $accountId,string $symbol,string $usdAmount,string $pin):array{
        $usdAmount=trim($usdAmount);
        if(!preg_match('/^\d+(\.\d{1,2})?$/',$usdAmount)||(float)$usdAmount<=0)throw new InvalidArgumentException('Invalid USD amount.');
        (new SecurityService($this->db))->verifyTransactionPin($userId,$pin);
        $symbol=strtoupper(trim($symbol));
        $rate=$this->rate($symbol); // pinned before the DB transaction
        $asset=$this->assetBySymbol($symbol);
        $w=$this->wallet($userId,$symbol);
        if($w['status']!=='active')throw new RuntimeException('Wallet is frozen.');
        $cryptoAmount=round((float)$usdAmount/$rate['rate'],(int)$asset['decimals']);
        if($cryptoAmount<=0)throw new InvalidArgumentException('Amount too small for this asset.');

        $this->db->beginTransaction();
        try{
            $account=$this->lockAccount($accountId,$userId);
            $balance=$this->ledgerBalance($accountId);
            if((float)$balance<(float)$usdAmount)throw new RuntimeException('Insufficient account balance. Available: '.number_format((float)$balance,2));
            $fiatRef='CRX-BUY-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
            $stmt=$this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,?,"processing",?,?,?,?)');
            $stmt->execute([$fiatRef,'crypto_purchase',$usdAmount,$account['currency'],'Crypto purchase '.$cryptoAmount.' '.$symbol,$userId]);
            $txId=(int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"debit",?)')->execute([$txId,$accountId,$usdAmount]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance($accountId),$accountId]);

            $s=$this->db->prepare('SELECT id FROM crypto_wallets WHERE id=? FOR UPDATE');$s->execute([$w['id']]);
            $this->db->prepare('UPDATE crypto_wallets SET balance=balance+? WHERE id=?')->execute([number_format($cryptoAmount,(int)$asset['decimals'],'.',''),$w['id']]);
            $ref=$this->ref('CRX-BUY');
            $this->db->prepare('INSERT INTO crypto_transactions(reference,user_id,asset_id,type,status,amount,to_address,description,completed_at) VALUES(?,?,?,?,"completed",?,?,?,NOW())')
                ->execute([$ref,$userId,$asset['id'],'buy',number_format($cryptoAmount,(int)$asset['decimals'],'.',''),$w['address'],sprintf('Bought %s %s for $%s (rate $%s, %s)',$cryptoAmount,$symbol,number_format((float)$usdAmount,2),number_format($rate['rate'],($rate['rate']<1?4:2)),$rate['source'])]);
            $this->db->commit();
            return ['reference'=>$ref,'fiat_reference'=>$fiatRef,'symbol'=>$symbol,'crypto_amount'=>$cryptoAmount,'usd_amount'=>$usdAmount,'rate'=>$rate['rate'],'source'=>$rate['source']];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /**
     * Sell crypto back to the fiat account at the current rate.
     * Debits the crypto wallet and credits the ledger-backed fiat account.
     */
    public function sell(int $userId,int $accountId,string $symbol,string $cryptoAmount,string $pin):array{
        $cryptoAmount=$this->amount($cryptoAmount);
        (new SecurityService($this->db))->verifyTransactionPin($userId,$pin);
        $symbol=strtoupper(trim($symbol));
        $rate=$this->rate($symbol);
        $asset=$this->assetBySymbol($symbol);
        $w=$this->wallet($userId,$symbol);
        if($w['status']!=='active')throw new RuntimeException('Wallet is frozen.');
        $usd=round((float)$cryptoAmount*$rate['rate'],2);
        if($usd<0.01)throw new InvalidArgumentException('Amount too small for this asset.');

        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT balance FROM crypto_wallets WHERE id=? FOR UPDATE');$s->execute([$w['id']]);
            if((float)$s->fetchColumn()<(float)$cryptoAmount)throw new RuntimeException('Insufficient crypto balance.');
            $this->db->prepare('UPDATE crypto_wallets SET balance=balance-? WHERE id=?')->execute([$cryptoAmount,$w['id']]);

            $account=$this->lockAccount($accountId,$userId);
            $fiatRef='CRX-SELL-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
            $stmt=$this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,?,"processing",?,?,?,?)');
            $stmt->execute([$fiatRef,'crypto_sale',number_format($usd,2,'.',''),$account['currency'],'Crypto sale '.$cryptoAmount.' '.$symbol,$userId]);
            $txId=(int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"credit",?)')->execute([$txId,$accountId,number_format($usd,2,'.','')]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance($accountId),$accountId]);

            $ref=$this->ref('CRX-SELL');
            $this->db->prepare('INSERT INTO crypto_transactions(reference,user_id,asset_id,type,status,amount,from_address,description,completed_at) VALUES(?,?,?,?,"completed",?,?,?,NOW())')
                ->execute([$ref,$userId,$asset['id'],'sell',$cryptoAmount,$w['address'],sprintf('Sold %s %s for $%s (rate $%s, %s)',$cryptoAmount,$symbol,number_format($usd,2),number_format($rate['rate'],($rate['rate']<1?4:2)),$rate['source'])]);
            $this->db->commit();
            return ['reference'=>$ref,'fiat_reference'=>$fiatRef,'symbol'=>$symbol,'crypto_amount'=>$cryptoAmount,'usd_amount'=>number_format($usd,2,'.',''),'rate'=>$rate['rate'],'source'=>$rate['source']];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function receive(int $userId,string $symbol,string $amount):string{$amount=$this->amount($amount);$w=$this->wallet($userId,$symbol);$this->db->beginTransaction();try{$ref=$this->ref('CRX-DEP');$this->db->prepare('UPDATE crypto_wallets SET balance=balance+? WHERE id=?')->execute([$amount,$w['id']]);$this->db->prepare('INSERT INTO crypto_transactions(reference,user_id,asset_id,type,status,amount,to_address,description,completed_at) VALUES(?,?,?,?,"completed",?,?,?,NOW())')->execute([$ref,$userId,$w['asset_id'],'receive',$amount,$w['address'],'Crypto receive']);$this->db->commit();return $ref;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}

    public function send(int $userId,string $symbol,string $amount,string $toAddress,string $pin):string{$amount=$this->amount($amount);$toAddress=trim($toAddress);if(strlen($toAddress)<10||strlen($toAddress)>128)throw new InvalidArgumentException('Invalid destination crypto address.');(new SecurityService($this->db))->verifyTransactionPin($userId,$pin);$w=$this->wallet($userId,$symbol);if($w['status']!=='active')throw new RuntimeException('Wallet is frozen.');$this->db->beginTransaction();try{$ref=$this->ref('CRX-SND');$this->db->prepare('UPDATE crypto_wallets SET balance=balance-? WHERE id=? AND balance>=?')->execute([$amount,$w['id'],$amount]);if($this->db->rowCount()!==1)throw new RuntimeException('Insufficient crypto balance.');$this->db->prepare('INSERT INTO crypto_transactions(reference,user_id,asset_id,type,status,amount,to_address,description,completed_at) VALUES(?,?,?,?,"completed",?,?,?,NOW())')->execute([$ref,$userId,$w['asset_id'],'send',$amount,$toAddress,'Crypto send']);$this->db->commit();return $ref;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}

    public function convert(int $userId,string $from,string $to,string $amount,string $pin):array{$amount=$this->amount($amount);$from=strtoupper($from);$to=strtoupper($to);if($from===$to)throw new InvalidArgumentException('Choose different cryptocurrencies.');(new SecurityService($this->db))->verifyTransactionPin($userId,$pin);$fw=$this->wallet($userId,$from);$tw=$this->wallet($userId,$to);$this->db->beginTransaction();try{$stmt=$this->db->prepare('SELECT balance FROM crypto_wallets WHERE id=? FOR UPDATE');$stmt->execute([$fw['id']]);$balance=(float)$stmt->fetchColumn();if($balance<(float)$amount)throw new RuntimeException('Insufficient crypto balance.');
            $fromRate=(float)$fw['eff_rate'];$toRate=(float)$tw['eff_rate'];
            $out=round((float)$amount*($fromRate/$toRate),(int)$tw['decimals']);
            $ref=$this->ref('CRX-CNV');$this->db->prepare('UPDATE crypto_wallets SET balance=balance-? WHERE id=?')->execute([$amount,$fw['id']]);$this->db->prepare('UPDATE crypto_wallets SET balance=balance+? WHERE id=?')->execute([$out,$tw['id']]);$this->db->prepare('INSERT INTO crypto_transactions(reference,user_id,asset_id,type,status,amount,counter_asset_id,counter_amount,description,completed_at) VALUES(?,?,?,?,"completed",?,?,?,?,NOW())')->execute([$ref,$userId,$fw['asset_id'],'convert',$amount,$tw['asset_id'],$out,"Converted $from to $to"]);$this->db->commit();return ['reference'=>$ref,'amount'=>$out,'symbol'=>$to];}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}

    public function history(int $userId,int $limit=50):array{$limit=max(1,min(100,$limit));$s=$this->db->prepare('SELECT t.*,a.symbol,ca.symbol counter_symbol FROM crypto_transactions t JOIN crypto_assets a ON a.id=t.asset_id LEFT JOIN crypto_assets ca ON ca.id=t.counter_asset_id WHERE t.user_id=? ORDER BY t.id DESC LIMIT '.$limit);$s->execute([$userId]);return $s->fetchAll();}

    private function assetBySymbol(string $symbol):array{$s=$this->db->prepare('SELECT * FROM crypto_assets WHERE symbol=? AND active=1 LIMIT 1');$s->execute([$symbol]);$a=$s->fetch();if(!$a)throw new RuntimeException('Crypto asset not found.');return $a;}

    private function lockAccount(int $accountId,int $userId):array{$s=$this->db->prepare('SELECT a.*,at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.id=? FOR UPDATE');$s->execute([$accountId]);$a=$s->fetch();if(!$a)throw new RuntimeException('Account not found.');if((int)$a['user_id']!==$userId)throw new RuntimeException('Account does not belong to you.');if($a['status']!=='active')throw new RuntimeException('Account is not active.');return $a;}

    private function ledgerBalance(int $accountId):string{$s=$this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');$s->execute([$accountId]);return number_format((float)$s->fetchColumn(),4,'.','');}

    private function amount(string $v):string{$v=trim($v);if(!preg_match('/^\d+(\.\d{1,18})?$/',$v)||!is_finite((float)$v)||(float)$v<=0)throw new InvalidArgumentException('Invalid crypto amount.');return $v;}
    private function ref(string $prefix):string{return $prefix.'-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));}
}
