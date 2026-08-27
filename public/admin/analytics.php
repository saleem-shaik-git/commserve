<?php
require_once dirname(__DIR__, 2) . '/app/helpers.php';
require_role('admin');
$db = Database::connection();

$byProduct = $db->query(
  'SELECT p.name, COUNT(a.id) accounts, COALESCE(AVG(a.available_balance),0) avg_balance, COALESCE(SUM(a.available_balance),0) deposits
   FROM savings_products p LEFT JOIN accounts a ON a.savings_product_id=p.id
   GROUP BY p.id ORDER BY deposits DESC'
)->fetchAll();

$interestExpense = (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM interest_postings')->fetchColumn();
$interestByProduct = $db->query(
  'SELECT p.name, COALESCE(SUM(ip.amount),0) total FROM savings_products p LEFT JOIN interest_postings ip ON ip.savings_product_id=p.id GROUP BY p.id HAVING total>0 ORDER BY total DESC'
)->fetchAll();

$fd = $db->query('SELECT COUNT(*) cnt, COALESCE(SUM(principal),0) principal FROM fixed_deposits WHERE status IN ("active","matured")')->fetch();

$loanTotals = $db->query(
  'SELECT COUNT(*) cnt, COALESCE(SUM(principal),0) disbursed, COALESCE(SUM(outstanding_principal),0) outstanding,
          COALESCE(SUM(status="active"),0) active, COALESCE(SUM(status="completed"),0) completed, COALESCE(SUM(status="defaulted"),0) defaulted,
          COALESCE(SUM(total_interest),0) interest_income
   FROM loans'
)->fetch();

$sched = $db->query('SELECT COUNT(*) total, COALESCE(SUM(status="paid"),0) paid, COALESCE(SUM(total_due),0) due, COALESCE(SUM(paid_amount),0) paid_amt FROM loan_schedule')->fetch();
$repaymentRate = ((int)$sched['total'] > 0) ? round(((int)$sched['paid'] / (int)$sched['total']) * 100, 1) : 0.0;
$defaultRate = ((int)$loanTotals['cnt'] > 0) ? round(((int)$loanTotals['defaulted'] / (int)$loanTotals['cnt']) * 100, 1) : 0.0;

$adoption = (int)$db->query('SELECT COUNT(DISTINCT user_id) FROM accounts WHERE savings_product_id IS NOT NULL')->fetchColumn();
$customers = (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id=(SELECT id FROM roles WHERE name='customer')")->fetchColumn();

$lifecycle = $db->query(
  "SELECT CASE
     WHEN u.lifecycle_override='restricted' THEN 'Restricted'
     WHEN u.lifecycle_override='closed' THEN 'Closed'
     WHEN k.status IS NOT NULL AND k.status<>'verified' THEN IF(k.status='pending','KYC Pending','Registered')
     WHEN k.status='verified' AND EXISTS(SELECT 1 FROM accounts a WHERE a.user_id=u.id) AND
          EXISTS(SELECT 1 FROM transactions t JOIN ledger_entries le ON le.transaction_id=t.id JOIN accounts a2 ON a2.id=le.account_id WHERE a2.user_id=u.id AND t.created_at>=CURRENT_DATE-INTERVAL 90 DAY) THEN 'Active'
     WHEN k.status='verified' AND EXISTS(SELECT 1 FROM accounts a WHERE a.user_id=u.id) THEN 'Dormant'
     WHEN k.status='verified' THEN 'KYC Approved'
     ELSE 'Registered' END stage, COUNT(*) cnt
   FROM users u LEFT JOIN customer_kyc k ON k.user_id=u.id
   WHERE u.role_id=(SELECT id FROM roles WHERE name='customer')
   GROUP BY stage ORDER BY cnt DESC"
)->fetchAll();

$pageTitle = t('Product Analytics');
$adminCurrent = 'analytics';
require __DIR__ . '/partials/header.php';
?>
<main class="container-fluid p-3 p-lg-4">
  <h2 class="fw-bold mb-3"><i class="bi bi-graph-up-arrow me-2"></i><?=e(t('Product Analytics'))?></h2>

  <div class="row g-3 mb-3">
    <?php foreach ([
      [t('Interest expense (paid)'), format_money($interestExpense)],
      [t('Deposits held (product accounts)'), format_money((float)array_sum(array_column($byProduct, 'deposits')))],
      [t('Fixed deposit book'), format_money((float)$fd['principal']) . ' (' . (int)$fd['cnt'] . ')'],
      [t('Loans disbursed'), format_money((float)$loanTotals['disbursed'])],
      [t('Outstanding loans'), format_money((float)$loanTotals['outstanding'])],
      [t('Interest income (scheduled)'), format_money((float)$loanTotals['interest_income'])],
      [t('Loan repayment rate'), $repaymentRate . '%'],
      [t('Default rate'), $defaultRate . '%'],
    ] as $c): ?>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
      <div class="text-muted small"><?=$c[0]?></div><div class="fs-4 fw-bold mt-1"><?=$c[1]?></div>
    </div></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-bold"><?=e(t('Deposits by product'))?></div>
        <div class="table-responsive"><table class="table table-sm mb-0 small">
          <thead class="table-light"><tr><th><?=e(t('Product'))?></th><th><?=e(t('Accounts'))?></th><th><?=e(t('Deposits'))?></th><th><?=e(t('Avg. balance'))?></th><th><?=e(t('Interest paid'))?></th></tr></thead>
          <tbody>
            <?php $interestByName = []; foreach ($interestByProduct as $r) $interestByName[$r['name']] = (float)$r['total']; ?>
            <?php foreach ($byProduct as $r): ?><tr>
              <td><?=e($r['name'])?></td><td><?=(int)$r['accounts']?></td><td class="fw-bold"><?=format_money((float)$r['deposits'])?></td>
              <td><?=format_money((float)$r['avg_balance'])?></td><td class="text-success"><?=format_money($interestByName[$r['name']] ?? 0)?></td>
            </tr><?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
      <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-bold"><?=e(t('Customer lifecycle'))?></div>
        <div class="table-responsive"><table class="table table-sm mb-0 small">
          <thead class="table-light"><tr><th><?=e(t('Stage'))?></th><th><?=e(t('Customers'))?></th></tr></thead>
          <tbody><?php foreach ($lifecycle as $r): ?><tr><td><?=e(t($r['stage']))?></td><td><?=(int)$r['cnt']?></td></tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="card-body small text-muted"><?=e(t('Product adoption'))?>: <b><?=(int)$adoption?></b> / <?=(int)$customers?> <?=e(t('customers hold a savings product account'))?></div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-bold"><?=e(t('Loan portfolio'))?></div>
        <div class="table-responsive"><table class="table table-sm mb-0 small">
          <thead class="table-light"><tr><th></th><th><?=e(t('Count'))?></th><th><?=e(t('Value'))?></th></tr></thead>
          <tbody>
            <tr><td><?=e(t('Active'))?></td><td><?=(int)$loanTotals['active']?></td><td><?=format_money((float)$loanTotals['outstanding'])?></td></tr>
            <tr><td><?=e(t('Completed'))?></td><td><?=(int)$loanTotals['completed']?></td><td>—</td></tr>
            <tr class="table-danger"><td><?=e(t('Defaulted'))?></td><td><?=(int)$loanTotals['defaulted']?></td><td>—</td></tr>
            <tr><td><?=e(t('Installments paid'))?></td><td><?=(int)$sched['paid']?> / <?=(int)$sched['total']?></td><td><?=format_money((float)$sched['paid_amt'])?> / <?=format_money((float)$sched['due'])?></td></tr>
          </tbody>
        </table></div>
      </div>
      <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-bold"><?=e(t('Product profitability simulation'))?></div>
        <div class="table-responsive"><table class="table table-sm mb-0 small">
          <thead class="table-light"><tr><th><?=e(t('Product'))?></th><th><?=e(t('Interest paid to customers'))?></th></tr></thead>
          <tbody>
            <?php foreach ($interestByProduct as $r): ?><tr><td><?=e($r['name'])?></td><td class="text-danger"><?=format_money((float)$r['total'])?></td></tr><?php endforeach; ?>
            <?php if (!$interestByProduct): ?><tr><td colspan="2" class="text-center text-muted py-3"><?=e(t('No interest posted yet.'))?></td></tr><?php endif; ?>
            <tr class="table-success"><td><b><?=e(t('Loan interest income (scheduled)'))?></b></td><td><b><?=format_money((float)$loanTotals['interest_income'])?></b></td></tr>
          </tbody>
        </table></div>
        <div class="card-body small text-muted"><?=e(t('Net simulation: loan interest income minus savings interest expense.'))?> <b><?=format_money((float)$loanTotals['interest_income'] - $interestExpense)?></b></div>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
