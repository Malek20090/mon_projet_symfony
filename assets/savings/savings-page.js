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

  // on load
  const url = new URL(window.location.href);
  setTab(url.searchParams.get("tab") || "savings");

  // Day/Night mode
  const modeToggle = document.getElementById("modeToggle");
  const modeLabel  = document.getElementById("modeLabel");

  const applyMode = (isNight) => {
    document.body.classList.toggle("night", isNight);
    if (modeLabel) modeLabel.textContent = isNight ? "Day" : "Night";
    const icon = modeToggle?.querySelector("i");
    if (icon) icon.className = isNight ? "fa-solid fa-sun" : "fa-solid fa-moon";
  };

  const savedMode = localStorage.getItem("decides_mode");
  applyMode(savedMode === "night");

  modeToggle?.addEventListener("click", () => {
    const isNight = !document.body.classList.contains("night");
    localStorage.setItem("decides_mode", isNight ? "night" : "day");
    applyMode(isNight);
  });

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
