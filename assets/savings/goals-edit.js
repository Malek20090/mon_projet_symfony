alert("goals-edit.js loaded");
console.log("✅ goals-edit.js LOADED");

function initGoalEdit() {
  console.log("✅ goals-edit.js init");

  const modal = document.getElementById('goalEditModal');
  const form  = document.getElementById('goalEditForm');

  const geNom   = document.getElementById('ge_nom');
  const geCible = document.getElementById('ge_cible');
  const gePrio  = document.getElementById('ge_prio');
  const geDate  = document.getElementById('ge_date');

  console.log("buttons found:", document.querySelectorAll('[data-open-goal-edit]').length);

  if (!modal || !form || !geNom || !geCible || !gePrio || !geDate) {
    console.error("❌ Missing modal/form/inputs");
    return;
  }

  // avoid double binding
  if (document.__goalEditBound) return;
  document.__goalEditBound = true;

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open-goal-edit]');
    if (!btn) return;

    if (!window.__goalEditUrlPattern) {
      console.error("❌ window.__goalEditUrlPattern not found (move the twig script higher or keep this fix)");
      return;
    }

    const id = btn.dataset.goalId || '';
    form.action = window.__goalEditUrlPattern.replace('__ID__', id);

    geNom.value   = btn.dataset.goalNom   || '';
    geCible.value = btn.dataset.goalCible || '';
    gePrio.value  = btn.dataset.goalPrio  || '3';
    geDate.value  = btn.dataset.goalDate  || '';

    modal.classList.add('show');
  });
}

document.addEventListener('DOMContentLoaded', initGoalEdit);
document.addEventListener('turbo:load', initGoalEdit); // au cas où Turbo est activé
