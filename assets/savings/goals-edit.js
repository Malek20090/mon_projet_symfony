function initGoalEdit() {
  const modal = document.getElementById('goalEditModal');
  const form = document.getElementById('goalEditForm');

  const geName = document.getElementById('ge_nom');
  const geCible = document.getElementById('ge_cible');
  const gePrio = document.getElementById('ge_prio');
  const geDate = document.getElementById('ge_date');

  if (!modal || !form || !geName || !geCible || !gePrio || !geDate) {
    return;
  }

  if (document.__goalEditBound) return;
  document.__goalEditBound = true;

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open-goal-edit]');
    if (!btn) return;

    if (!window.__goalEditUrlPattern) {
      return;
    }

    const id = btn.dataset.goalId || '';
    form.action = window.__goalEditUrlPattern.replace('__ID__', id);

    geName.value = btn.dataset.goalName || '';
    geCible.value = btn.dataset.goalCible || '';
    gePrio.value = btn.dataset.goalPrio || '3';
    geDate.value = btn.dataset.goalDate || '';

    modal.classList.add('show');
  });
}

document.addEventListener('DOMContentLoaded', initGoalEdit);
document.addEventListener('turbo:load', initGoalEdit);
