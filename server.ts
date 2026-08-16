import express from "express";
import { createProxyMiddleware } from "http-proxy-middleware";
import { spawn, execSync } from "child_process";
import path from "path";
import fs from "fs";

const app = express();
const PORT = 3000;

// Ensure MariaDB is running
function ensureServices() {
  try {
    execSync("mariadb-admin ping || (mariadbd-safe --user=mysql &)", { stdio: "ignore" });
  } catch (e) {
    console.log("MariaDB check error:", e);
  }

  // Ensure Database has tables
  try {
    const tableCount = execSync("mariadb -u root -D project2 -e 'SHOW TABLES;' 2>/dev/null | wc -l", { encoding: "utf-8" });
    if (parseInt(tableCount.trim(), 10) <= 1) {
      console.log("Seeding database project2 from db.sql...");
      execSync("mariadb -u root -e 'CREATE DATABASE IF NOT EXISTS project2; USE project2; SOURCE db.sql;'", { stdio: "inherit" });
    }
  } catch (e) {
    console.log("DB seed check:", e);
  }

  // Start Python Forecast Service on port 8001
  try {
    const forecastCheck = execSync("curl -s http://127.0.0.1:8001/health 2>/dev/null || true", { encoding: "utf-8" });
    if (!forecastCheck.includes("gs25-demand-forecast-api")) {
      console.log("Starting Python Forecast API on port 8001...");
      const forecastProcess = spawn("python3", ["-m", "uvicorn", "app.main:app", "--host", "127.0.0.1", "--port", "8001"], {
        cwd: path.join(process.cwd(), "forecast-api"),
        detached: true,
        stdio: "ignore",
      });
      forecastProcess.unref();
    }
  } catch (e) {
    console.log("Forecast service start check:", e);
  }

  // Start PHP server on port 8080
  try {
    const phpCheck = execSync("curl -s http://127.0.0.1:8080/frontend/login.php 2>/dev/null || true", { encoding: "utf-8" });
    if (!phpCheck.includes("InventoryDSS") && !phpCheck.includes("Sign In")) {
      console.log("Starting PHP Server on port 8080 with router.php...");
      const phpProcess = spawn("php", ["-S", "127.0.0.1:8080", "router.php"], {
        cwd: process.cwd(),
        detached: true,
        stdio: "ignore",
      });
      phpProcess.unref();
    }
  } catch (e) {
    console.log("PHP service start check:", e);
  }
}

ensureServices();

// Health check endpoint for container
app.get("/api/health", (req, res) => {
  res.json({ status: "ok", app: "Gs25IntelliStock (PHP+MariaDB+FastAPI)" });
});

// Proxy all other web traffic directly to PHP Backend on port 8080
const phpProxy = createProxyMiddleware({
  target: "http://127.0.0.1:8080",
  changeOrigin: true,
  ws: true,
  xfwd: true,
  cookieDomainRewrite: "",
  on: {
    proxyRes: (proxyRes, req, res) => {
      // Ensure all set-cookie headers work smoothly in cross-site iframe previews
      const rawCookies = proxyRes.headers["set-cookie"];
      if (rawCookies) {
        const cookiesArray = Array.isArray(rawCookies) ? rawCookies : [rawCookies];
        proxyRes.headers["set-cookie"] = cookiesArray.map((cookieStr) => {
          let updated = cookieStr;
          if (/samesite=/i.test(updated)) {
            updated = updated.replace(/samesite=[^;]+/i, "SameSite=None");
          } else {
            updated += "; SameSite=None";
          }
          if (!/secure/i.test(updated)) {
            updated += "; Secure";
          }
          if (!/partitioned/i.test(updated)) {
            updated += "; Partitioned";
          }
          return updated;
        });
      }
    },
    error: (err, req, res) => {
      console.error("Proxy error:", err);
      if ("writeHead" in res && typeof res.writeHead === "function") {
        res.writeHead(502, { "Content-Type": "text/html; charset=utf-8" });
        res.end(`<h3>Đang khởi động dịch vụ GS25 IntelliStock... Vui lòng tải lại trang sau giây lát.</h3>`);
      }
    },
  },
});

app.use(phpProxy);

app.listen(PORT, "0.0.0.0", () => {
  console.log(`Gs25IntelliStock Server running at http://0.0.0.0:${PORT}`);
});
