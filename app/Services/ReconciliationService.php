<?php

declare(strict_types=1);

final class ReconciliationService
{
    public function __construct(private PDO $db) {}

    public function run(): array
    {
        $stmt=$this->db->query('INSERT INTO reconciliation_runs(status) VALUES("running")');$runId=(int)$this->db->lastInsertId();
        $accounts=$this->db->query('SELECT id,available_balance FROM accounts WHERE status<>"closed"')->fetchAll();$mismatches=[];
        $calc=$this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');
        foreach($accounts as $account){$calc->execute([(int)$account['id']]);$ledger=(float)$calc->fetchColumn();$stored=(float)$account['available_balance'];if(abs($ledger-$stored)>0.0001)$mismatches[]=['account_id'=>(int)$account['id'],'stored'=>$stored,'ledger'=>$ledger,'difference'=>$ledger-$stored];}
        $status=$mismatches?'failed':'passed';$stmt=$this->db->prepare('UPDATE reconciliation_runs SET status=?,accounts_checked=?,mismatches_found=?,completed_at=CURRENT_TIMESTAMP,details=? WHERE id=?');$stmt->execute([$status,count($accounts),count($mismatches),$mismatches?json_encode($mismatches,JSON_THROW_ON_ERROR):null,$runId]);return ['run_id'=>$runId,'status'=>$status,'accounts_checked'=>count($accounts),'mismatches'=>$mismatches];
    }
}
