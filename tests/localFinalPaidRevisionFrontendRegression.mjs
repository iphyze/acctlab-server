import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(import.meta.dirname, '..');
const editor = fs.readFileSync(path.join(root, 'src/pages/payments/LocalFinalPurchaseEditorPage.jsx'), 'utf8');
const details = fs.readFileSync(path.join(root, 'src/pages/payments/LocalFinalPurchaseDetailsPage.jsx'), 'utf8');
const helpers = fs.readFileSync(path.join(root, 'src/pages/payments/localFinalPurchaseHelpers.js'), 'utf8');
const router = fs.readFileSync(path.join(root, 'src/router/index.jsx'), 'utf8');

const checks = {
  paid_revision_flag_is_normalized: helpers.includes('is_paid_revisable: Boolean(record.is_paid_revisable)'),
  paid_revision_requires_permission: editor.includes("payments.local_final.amend_paid_purchase"),
  paid_revision_requires_reason: editor.includes("validation.revision_reason = 'Revision reason is required.'"),
  paid_revision_reason_is_submitted: editor.includes('revision_reason: form.revision_reason.trim()'),
  details_expose_paid_revision_action: details.includes('Revise paid purchase'),
  edit_route_accepts_revision_permission: router.includes("anyPermissions={['payments.local_final.update', 'payments.local_final.amend_paid_purchase']}"),
};

const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
console.log(JSON.stringify({ healthy: failed.length === 0, checks, failed }, null, 2));
process.exit(failed.length === 0 ? 0 : 1);
