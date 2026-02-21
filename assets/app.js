import './bootstrap.js';
import './savings/goals-edit.js';

document.addEventListener("DOMContentLoaded", () => {
  /* =========================================================
     REVEAL (safe)
     ========================================================= */
  const els = document.querySelectorAll(".reveal");
  if (els.length) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) e.target.classList.add("in-view");
      });
    }, { threshold: 0.12 });

    els.forEach(el => io.observe(el));
  }

  /* =========================================================
     COUNT-UP (safe)
     ========================================================= */
  const countups = document.querySelectorAll(".countup");
  const animateCount = (el) => {
    const target = Number(el.getAttribute("data-value") || "0");
    const duration = 800;
    const start = performance.now();
    const from = 0;

    const step = (t) => {
      const p = Math.min(1, (t - start) / duration);
      const val = Math.floor(from + (target - from) * p);
      el.textContent = String(val);
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  if (countups.length) {
    const ioCount = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting && !e.target.dataset.done) {
          e.target.dataset.done = "1";
          animateCount(e.target);
        }
      });
    }, { threshold: 0.4 });

    countups.forEach(c => ioCount.observe(c));
  }

  /* =========================================================
     SCROLL-TOP (safe)
     ========================================================= */
  const scrollTopBtn = document.querySelector(".scroll-top");
  const onScroll = () => {
    if (!scrollTopBtn) return;
    if (window.scrollY > 400) scrollTopBtn.classList.add("show");
    else scrollTopBtn.classList.remove("show");
  };
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* =========================================================
     NIGHT MODE (WORKING + SAVED)
     Requirements:
     - Your CSS uses body.night ...
     - Your toggle button either:
       #nightBtn  OR  [data-night-toggle]
     ========================================================= */
  const nightBtn =
    document.getElementById("nightBtn") ||
    document.querySelector("[data-night-toggle]");

  // restore saved mode
  const savedNight = localStorage.getItem("decides_night");
  if (savedNight === "1") document.body.classList.add("night");

  const updateNightLabel = () => {
    if (!nightBtn) return;
    const isNight = document.body.classList.contains("night");

    // optional: change text/icon if you want
    // If your button already has an icon/text you like, remove these 2 lines
    if (nightBtn.tagName === "BUTTON") {
      nightBtn.textContent = isNight ? "☀ Day" : "🌙 Night";
    }
    nightBtn.setAttribute("aria-pressed", isNight ? "true" : "false");
  };

  updateNightLabel();

  if (nightBtn) {
    nightBtn.addEventListener("click", (e) => {
      e.preventDefault();
      document.body.classList.toggle("night");
      localStorage.setItem(
        "decides_night",
        document.body.classList.contains("night") ? "1" : "0"
      );
      updateNightLabel();
    });
  }

  /* =========================================================
     TAB SWITCH (same page)
     ========================================================= */
  const tabs = document.querySelectorAll(".js-tab");
  const panelSavings = document.getElementById("tab-savings");
  const panelGoals = document.getElementById("tab-goals");

  const setTab = (name) => {
    const tabName = (name === "goals") ? "goals" : "savings";

    // buttons
    tabs.forEach(t => t.classList.toggle("active", t.dataset.tab === tabName));

    // panels
    if (panelSavings) panelSavings.classList.toggle("show", tabName === "savings");
    if (panelGoals) panelGoals.classList.toggle("show", tabName === "goals");

    // update url ?tab=
    const url = new URL(window.location.href);
    url.searchParams.set("tab", tabName);
    window.history.replaceState({}, "", url.toString());
  };

  if (tabs.length) {
    tabs.forEach(b => {
      b.addEventListener("click", () => setTab(b.dataset.tab));
    });

    // initial tab from URL
    const url = new URL(window.location.href);
    const initialTab = url.searchParams.get("tab") || "savings";
    setTab(initialTab);
  }
});
