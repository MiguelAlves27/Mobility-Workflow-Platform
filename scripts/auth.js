function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

async function checkLogin() {
  try {
    const r = await fetch(`${SERVER}/whoami.php`, { credentials: "include" });
    const data = await r.json();

    if (!r.ok || !data.authenticated) {
      setText("loginStatus", "Not logged in");
      return null;
    }

    const name = data.person?.name || "Student";
    setText("loginStatus", `Logged in as ${name}`);
    return data;
  } catch (e) {
    setText("loginStatus", "Not logged in");
    return null;
  }
}

function wireButtons() {
  const loginBtn = document.getElementById("loginBtn");
  const goGeneratorBtn = document.getElementById("goGeneratorBtn");
  const logoutBtn = document.getElementById("logoutBtn");

  if (loginBtn) {
    loginBtn.addEventListener("click", () => {
      window.location.href = `${SERVER}/login.php`;
    });
  }

  if (goGeneratorBtn) {
    goGeneratorBtn.addEventListener("click", () => {
      window.location.href = `${BASE_PATH}/confirmation_page.html`;
    });
  }

  if (logoutBtn) {
    logoutBtn.addEventListener("click", () => {
      window.location.href = `${SERVER}/logout.php`;
    });
  }
}

document.addEventListener("DOMContentLoaded", () => {
  setText("cbUrl", `https://mobilidade.dei.tecnico.ulisboa.pt/server/callback.php`);
  wireButtons();
  checkLogin();
});
