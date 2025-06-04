const express = require("express");
const puppeteer = require("puppeteer-extra");
const StealthPlugin = require("puppeteer-extra-plugin-stealth");

puppeteer.use(StealthPlugin());

const app = express();
const PORT = 3000;

const IMPACT_ALPHA_USERNAME = "iixadmin@iixglobal.com";
const IMPACT_ALPHA_PASSWORD = "iixadmin2020";

app.get("/article", async (req, res) => {
    const articleUrl = req.query.url;
    if (!articleUrl || !/^https:\/\/impactalpha\.com/.test(articleUrl)) {
        return res.status(400).send("Invalid or missing article URL");
    }

    try {
        const browser = await puppeteer.launch({
            headless: true,
            args: ["--no-sandbox", "--disable-setuid-sandbox"],
        });

        const page = await browser.newPage();
        await page.setUserAgent(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114 Safari/537.36"
        );

        // 1. Go to homepage
        await page.goto("https://impactalpha.com", {
            waitUntil: "networkidle2",
        });

        // 2. Click the Login button using exact selector
        await page.waitForSelector(
            "#header-user-state > div > a.btn.btn-secondary.btn-sm-on-mobile.mr-2.mr-sm-3.loginlink",
            { timeout: 20000 }
        );
        await page.click(
            "#header-user-state > div > a.btn.btn-secondary.btn-sm-on-mobile.mr-2.mr-sm-3.loginlink"
        );

        // 3. Wait for the login modal to appear
        await page.evaluate(
            (email, password) => {
                const appMain = document.querySelector("app-main");
                const appWidget =
                    appMain?.shadowRoot?.querySelector("app-widget");
                const emailInput = appWidget?.shadowRoot?.querySelector(
                    'input[type="email"]'
                );
                const passwordInput = appWidget?.shadowRoot?.querySelector(
                    'input[type="password"]'
                );
                const loginButton =
                    appWidget?.shadowRoot?.querySelector("button");

                if (emailInput) {
                    emailInput.value = email;
                    console.log("Email input set:", email);
                } else {
                    console.log("Email input NOT found");
                }

                if (passwordInput) {
                    passwordInput.value = password;
                    console.log("Password input set:", password);
                } else {
                    console.log("Password input NOT found");
                }

                if (loginButton) {
                    loginButton.click();
                    console.log("Login button clicked");
                } else {
                    console.log("Login button NOT found");
                }
            },
            IMPACT_ALPHA_USERNAME,
            IMPACT_ALPHA_PASSWORD
        );

        const html = await page.content();
        await browser.close();

        res.set("Content-Type", "text/html");
        return res.send(html);
    } catch (err) {
        console.error("ERROR:", err.message);
        return res.status(500).send("Failed to fetch article");
    }
});

app.listen(PORT, () => {
    console.log(`Server is running on http://localhost:${PORT}`);
});
