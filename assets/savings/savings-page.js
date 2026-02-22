document.addEventListener("DOMContentLoaded", () => {
  // Tabs + URL sync (?tab=savings|goals)
  const setTab = (tab) => {
    document.querySelectorAll(".tab-pill").forEach(b => b.classList.remove("active"));
    document.querySelectorAll(".tab-panel").forEach(p => p.classList.remove("show"));

    const pill = document.querySelector('.tab-pill[data-tab="'+tab+'"]');
    if (pill) pill.classList.add("active");

    const panel = document.getElementById("tab-" + tab);
    if (panel) panel.classList.add("show");

    const url = new URL(window.location.href);
    url.searchParams.set("tab", tab);
    window.history.replaceState({}, "", url);
  };

  document.querySelectorAll(".js-tab").forEach(btn => {
    btn.addEventListener("click", () => setTab(btn.dataset.tab));
  });

  const focusForm = (tab, formId) => {
    setTab(tab);
    window.setTimeout(() => {
      const form = document.getElementById(formId);
      if (!form) return;
      form.scrollIntoView({ behavior: "smooth", block: "center" });
      const firstInput = form.querySelector("input, select, textarea");
      if (firstInput) firstInput.focus({ preventScroll: true });
    }, 60);
  };

  document.getElementById("heroAddDeposit")?.addEventListener("click", (e) => {
    e.preventDefault();
    focusForm("savings", "depositForm");
  });

  document.getElementById("heroCreateGoal")?.addEventListener("click", (e) => {
    e.preventDefault();
    focusForm("goals", "goalAddForm");
  });

  // on load
  const url = new URL(window.location.href);
  setTab(url.searchParams.get("tab") || "savings");

  // Day/Night mode
  const modeToggle = document.getElementById("modeToggle");
  const modeLabel  = document.getElementById("modeLabel");

  const applyMode = (isNight) => {
    document.body.classList.toggle("night", isNight);
    document.body.classList.toggle("theme-dark", isNight);
    if (modeLabel) modeLabel.textContent = isNight ? "Day" : "Night";
    const icon = modeToggle?.querySelector("i");
    if (icon) icon.className = isNight ? "fa-solid fa-sun" : "fa-solid fa-moon";
  };

  // Avoid overriding global theme-toggle unless this page-level toggle exists.
  if (modeToggle) {
    const savedMode = localStorage.getItem("decides_mode");
    applyMode(savedMode === "night");

    modeToggle.addEventListener("click", () => {
      const isNight = !document.body.classList.contains("night");
      localStorage.setItem("decides_mode", isNight ? "night" : "day");
      localStorage.setItem("decides_theme_dark", isNight ? "1" : "0");
      localStorage.setItem("decides_night", isNight ? "1" : "0");
      applyMode(isNight);
    });
  }

  // Modals
  const openModal = (id) => document.getElementById(id)?.classList.add("show");
  const closeAllModals = () => document.querySelectorAll(".modal").forEach(m => m.classList.remove("show"));

  document.getElementById("openCalendar")?.addEventListener("click", () => openModal("calendarModal"));
  document.getElementById("openSimulation")?.addEventListener("click", () => openModal("simModal"));

  document.querySelectorAll("[data-close-modal]").forEach(btn => {
    btn.addEventListener("click", closeAllModals);
  });
  document.querySelectorAll(".modal").forEach(m => {
    m.addEventListener("click", (e) => { if (e.target === m) closeAllModals(); });
  });

  // Simulation
  const simRange = document.getElementById("simRange");
  const simVal   = document.getElementById("simVal");
  const simMonths= document.getElementById("simMonths");

  const targetDemo = 2000;
  const updateSim = () => {
    const v = Number(simRange?.value || 0);
    if (simVal) simVal.textContent = v;
    if (simMonths) simMonths.textContent = v > 0 ? Math.ceil(targetDemo / v) + " months" : "—";
  };
  simRange?.addEventListener("input", updateSim);
  updateSim();
});
