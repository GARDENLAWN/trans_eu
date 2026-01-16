import sys
import json
import time
import os
import shutil
import glob
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

# Set HOME to /tmp to avoid permission issues with cache
os.environ['HOME'] = '/tmp'

# Persistent profile directory
PROFILE_DIR = "/var/www/html/magento/var/trans_eu_chrome_profile"

# Find chromedriver automatically
CHROMEDRIVER_PATH = shutil.which("chromedriver")
if not CHROMEDRIVER_PATH:
    CHROMEDRIVER_PATH = "/usr/local/bin/chromedriver" # Fallback

def cleanup_profile_locks(profile_path):
    """Removes Chrome lock files that might prevent startup"""
    locks = [
        os.path.join(profile_path, "SingletonLock"),
        os.path.join(profile_path, "SingletonCookie"),
        os.path.join(profile_path, "Lockfile")
    ]
    for lock in locks:
        try:
            if os.path.exists(lock):
                os.remove(lock)
                # print(f"Removed lock file: {lock}", file=sys.stderr)
        except Exception as e:
            pass

def get_token(username, password):
    # Try to clean up locks before starting
    cleanup_profile_locks(PROFILE_DIR)

    options = Options()
    options.add_argument("--headless") # Run in background
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--disable-gpu")
    options.add_argument("--window-size=1920,1080")
    options.add_argument("--remote-debugging-port=9222")

    # Crash recovery
    options.add_argument("--disable-session-crashed-bubble")
    options.add_argument("--disable-infobars")

    # Use persistent user data dir
    options.add_argument(f"--user-data-dir={PROFILE_DIR}")

    # Spoof user agent
    options.add_argument("user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

    # Hide automation flags
    options.add_argument("--disable-blink-features=AutomationControlled")
    options.add_experimental_option("excludeSwitches", ["enable-automation"])
    options.add_experimental_option('useAutomationExtension', False)

    service = Service(executable_path=CHROMEDRIVER_PATH)
    driver = None
    try:
        driver = webdriver.Chrome(service=service, options=options)

        # Stealth mode
        driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
            "source": """
                Object.defineProperty(navigator, 'webdriver', {
                    get: () => undefined
                })
            """
        })

        # 1. Go to login page
        print("Navigating to login page...", file=sys.stderr)
        driver.get("https://auth.platform.trans.eu/accounts/login")

        # 2. Check if already logged in
        try:
            WebDriverWait(driver, 5).until(EC.url_contains("platform.trans.eu"))
            if "sso" in driver.current_url: time.sleep(5)
            token = extract_token(driver)
            if token: return token
        except:
            pass

        # 3. Wait for login form
        print("Waiting for login form...", file=sys.stderr)
        wait = WebDriverWait(driver, 20)

        try:
            wait.until(EC.presence_of_element_located((By.NAME, "login")))

            username_input = driver.find_element(By.NAME, "login")
            driver.execute_script("arguments[0].value = '';", username_input)
            username_input.send_keys(username)

            password_input = driver.find_element(By.NAME, "password")
            driver.execute_script("arguments[0].value = '';", password_input)
            password_input.send_keys(password)

            submit_btn = driver.find_element(By.CSS_SELECTOR, "button[type='submit']")
            driver.execute_script("arguments[0].click();", submit_btn)
            print("Submitted login form.", file=sys.stderr)

        except Exception as e:
            print(f"Login form interaction failed (might be already logged in?): {e}", file=sys.stderr)

        # 4. Wait for redirect or MFA
        print("Waiting for redirect/MFA...", file=sys.stderr)

        for i in range(15):
            time.sleep(5)
            current_url = driver.current_url
            print(f"Loop {i}: {current_url}", file=sys.stderr)

            if "mfa/auth" in current_url or "Logowanie z nieznanego urządzenia" in driver.page_source:
                print("MFA Detected!", file=sys.stderr)

                if not sys.stdin.isatty():
                     return "Error: MFA Required but script is running in background (Cron). Run manually to authorize."

                try:
                    email_btn = driver.find_element(By.CSS_SELECTOR, "div[data-ctx-id='email'] button")
                    email_btn.click()
                    print("Clicked 'Send Email'.", file=sys.stderr)
                except Exception as e:
                    print(f"Could not click 'Send Email': {e}", file=sys.stderr)

                print("Enter the verification code from email: ", file=sys.stderr, end='')
                sys.stderr.flush()

                try:
                    code = input()
                except EOFError:
                    return "Error: MFA Required but no input provided (EOF)."

                code = code.strip()
                print(f"Received code: {code}", file=sys.stderr)

                for j in range(6):
                    try:
                        input_field = driver.find_element(By.NAME, f"otp-input-{j}")
                        input_field.send_keys(code[j])
                        time.sleep(0.5)
                    except:
                        pass

                time.sleep(2)

                try:
                    confirm_btn = driver.find_element(By.CSS_SELECTOR, "button[data-ctx='auth-submit']")
                    if not confirm_btn.is_enabled():
                        time.sleep(2)
                    confirm_btn.click()
                    print("Clicked Confirm.", file=sys.stderr)
                except:
                    print("Could not find Confirm button.", file=sys.stderr)

                time.sleep(10)
                break

            token = extract_token(driver)
            if token:
                print("Token found!", file=sys.stderr)
                return token

        # 5. Final check
        token = extract_token(driver)
        if token:
            return token
        else:
            debug_info = {
                "url": driver.current_url,
                "cookies": [c['name'] for c in driver.get_cookies()],
                "localStorage": driver.execute_script("return Object.keys(localStorage);"),
                "sessionStorage": driver.execute_script("return Object.keys(sessionStorage);")
            }
            return f"Error: Token not found. Debug: {json.dumps(debug_info)}"

    except Exception as e:
        return f"Error: {str(e)}"
    finally:
        if driver:
            driver.quit()

def extract_token(driver):
    try:
        token = driver.execute_script("return localStorage.getItem('access_token');")
        if token: return token
    except: pass

    try:
        script = """
        for (var i = 0; i < localStorage.length; i++) {
            var key = localStorage.key(i);
            var value = localStorage.getItem(key);
            if (value && value.startsWith('eyJ') && value.split('.').length === 3) return value;
            try {
                var obj = JSON.parse(value);
                if (obj.access_token) return obj.access_token;
            } catch(e) {}
        }
        return null;
        """
        token = driver.execute_script(script)
        if token: return token
    except: pass

    try:
        cookies = driver.get_cookies()
        for cookie in cookies:
            if cookie['name'] in ['access_token', 'token', 'auth_token', 'jwt']:
                return cookie['value']
    except: pass

    return None

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "message": "Usage: python get_token.py <username> <password>"}))
        sys.exit(1)

    u = sys.argv[1]
    p = sys.argv[2]

    result_token = get_token(u, p)

    if result_token and not result_token.startswith("Error:"):
        print(json.dumps({"success": True, "token": result_token}))
    else:
        print(json.dumps({"success": False, "message": result_token}))
