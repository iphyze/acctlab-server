import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(import.meta.dirname, '..');
const finalDetails = fs.readFileSync(path.join(root, 'src/pages/payments/LocalFinalPurchaseDetailsPage.jsx'), 'utf8');
const advanceDetails = fs.readFileSync(path.join(root, 'src/pages/payments/LocalAdvancePurchaseDetailsPage.jsx'), 'utf8');
const finalEditor = fs.readFileSync(path.join(root, 'src/pages/payments/LocalFinalPurchaseEditorPage.jsx'), 'utf8');
const advanceEditor = fs.readFileSync(path.join(root, 'src/pages/payments/LocalAdvancePurchaseEditorPage.jsx'), 'utf8');

const checks = {
  final_cancelled_purchase_exposes_replacement_action:
    finalDetails.includes("record.po_status === 'Cancelled'")
    && finalDetails.includes('Create replacement')
    && finalDetails.includes("/payments/local/final/create"),

  advance_cancelled_purchase_exposes_replacement_action:
    advanceDetails.includes("record.po_status === 'Cancelled'")
    && advanceDetails.includes('Create replacement')
    && advanceDetails.includes("/payments/local/advance/create"),

  final_replacement_is_prefilled_but_supplier_and_identifiers_are_reset:
    finalEditor.includes('location.state?.replacementOf')
    && finalEditor.includes("po_number: ''")
    && finalEditor.includes("purchase_number: ''")
    && finalEditor.includes("supplier_id: ''")
    && finalEditor.includes('replacement_of_id: Number(location.state.replacementOf.id)'),

  advance_replacement_is_prefilled_but_supplier_and_identifiers_are_reset:
    advanceEditor.includes('location.state?.replacementOf')
    && advanceEditor.includes("po_number: ''")
    && advanceEditor.includes("purchase_number: ''")
    && advanceEditor.includes("supplier_id: ''")
    && advanceEditor.includes('replacement_of_id: Number(location.state.replacementOf.id)'),
};

const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
console.log(JSON.stringify({ healthy: failed.length === 0, checks, failed }, null, 2));
process.exit(failed.length === 0 ? 0 : 1);
