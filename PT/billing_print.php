<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
$invoiceId = (int) ($_GET['id'] ?? 0);

if ($invoiceId <= 0) {
    denyAccess('Invoice not found.');
}

$actorType = null;
$currentUser = null;
$currentCustomer = null;

if (isAuthenticated()) {
    requirePermission('billing.print');
    $actorType = 'staff';
    $currentUser = currentUser();
} elseif (isCustomerAuthenticated()) {
    $actorType = 'customer';
    $currentCustomer = currentCustomer();
} else {
    redirectTo('member/index.php?login_required=1');
}

$invoiceStatement = $pdo->prepare(
    'SELECT i.*, c.name AS customer_name, c.customer_code, c.phone, c.whatsapp, c.address, s.id AS subscription_id, p.name AS package_name, p.code AS package_code FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id WHERE i.id = :id LIMIT 1'
);
$invoiceStatement->execute([':id' => $invoiceId]);
$invoice = $invoiceStatement->fetch();

if (!$invoice) {
    denyAccess('Invoice not found.');
}

if ($actorType === 'customer' && (int) $invoice['customer_id'] !== (int) $currentCustomer['id']) {
    denyAccess('This invoice does not belong to your account.');
}

$invoice['effective_status'] = effectiveInvoiceStatus($invoice);

if ($actorType === 'staff') {
    logActivity($pdo, (int) $currentUser['id'], 'invoice_printed', 'Invoice printed: ' . $invoice['invoice_number']);
} else {
    logCustomerActivity($pdo, (int) $currentCustomer['id'], 'invoice_printed', 'Invoice printed: ' . $invoice['invoice_number']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #fff;
            color: #111827;
        }

        .invoice-print {
            max-width: 820px;
            margin: 30px auto;
            padding: 32px;
            border: 1px solid #dfe5ee;
            border-radius: 18px;
            box-shadow: 0 14px 25px rgba(15, 23, 42, 0.06);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .brand {
            font-size: 1.9rem;
            font-weight: 800;
            letter-spacing: -0.05em;
            color: #0f172a;
        }

        .meta {
            text-align: right;
            color: #475569;
            font-size: 0.92rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            margin-bottom: 0.35rem;
        }

        .value {
            font-size: 1rem;
            font-weight: 600;
            color: #0f172a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 0.7rem 0.8rem;
            text-align: left;
        }

        th {
            background: #f8fafc;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
        }

        .total-row td {
            font-weight: 700;
            color: #0f172a;
        }

        .badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            background: rgba(148, 163, 184, 0.15);
            color: #334155;
        }

        .badge-paid { background: rgba(22, 163, 74, 0.12); color: #166534; }
        .badge-overdue { background: rgba(220, 38, 38, 0.12); color: #991b1b; }
        .badge-cancelled { background: rgba(148, 163, 184, 0.15); color: #475569; }
        .badge-unpaid { background: rgba(217, 119, 6, 0.12); color: #92400e; }

        @media print {
            body {
                background: #fff;
            }

            .invoice-print {
                margin: 0;
                border: 0;
                box-shadow: none;
                border-radius: 0;
            }
        }

        @media (max-width: 600px) {
            .invoice-print {
                margin: 0;
                padding: 1.25rem;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .header {
                flex-direction: column;
            }

            .meta {
                text-align: left;
                overflow-wrap: anywhere;
            }

            .grid {
                grid-template-columns: minmax(0, 1fr);
            }

            th, td {
                padding: 0.65rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-print">
        <div class="header">
            <div>
                <div class="brand">ISP Management</div>
                <div style="margin-top:0.4rem; color:#475569; font-size:0.92rem;">Invoice</div>
            </div>
            <div class="meta">
                <div><strong>Invoice Number:</strong> <?php echo htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>Status:</strong> <span class="badge badge-<?php echo strtolower(htmlspecialchars($invoice['effective_status'], ENT_QUOTES, 'UTF-8')); ?>"><?php echo htmlspecialchars(invoiceStatusBadgeLabel($invoice['effective_status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
        </div>

        <div class="grid">
            <div>
                <span class="label">Customer</span>
                <div class="value"><?php echo htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div style="margin-top:0.25rem; color:#475569;">Code: <?php echo htmlspecialchars($invoice['customer_code'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <span class="label">Billing</span>
                <div class="value"><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['billing_period_start'])) . ' to ' . date('d-m-Y', strtotime($invoice['billing_period_end'])), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <span class="label">Phone</span>
                <div class="value"><?php echo htmlspecialchars((string) ($invoice['phone'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <span class="label">WhatsApp</span>
                <div class="value"><?php echo htmlspecialchars((string) ($invoice['whatsapp'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <span class="label">Issue Date</span>
                <div class="value"><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['issue_date'])), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <span class="label">Due Date</span>
                <div class="value"><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['due_date'])), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <span class="label">Package</span>
                <div class="value"><?php echo htmlspecialchars($invoice['package_code'] . ' - ' . $invoice['package_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <span class="label">Address</span>
                <div class="value"><?php echo htmlspecialchars((string) ($invoice['address'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Subtotal</td>
                    <td><?php echo htmlspecialchars(formatCurrency((float) $invoice['subtotal']), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td>Discount</td>
                    <td><?php echo htmlspecialchars(formatCurrency((float) $invoice['discount']), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td>Tax</td>
                    <td><?php echo htmlspecialchars(formatCurrency((float) $invoice['tax']), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr class="total-row">
                    <td>Total</td>
                    <td><?php echo htmlspecialchars(formatCurrency((float) $invoice['total']), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($invoice['notes'])): ?>
            <div style="margin-top:1.25rem;">
                <div class="label">Notes</div>
                <div class="value" style="font-weight:400; white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($invoice['notes'], ENT_QUOTES, 'UTF-8')); ?></div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
