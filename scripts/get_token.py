import sys
import json
import time
import os
import shutil
import base64
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import TimeoutException, NoSuchElementException

# Set HOME to /tmp to avoid permission issues with cache
os.environ['HOME'] = '/tmp'

# Persistent profile directory
PROFILE_DIR = "/var/www/html/magento/var/trans_eu_chrome_profile"

# Find chromedriver automatically
CHROMEDRIVER_PATH = shutil.which("chromedriver")

# Fallback paths
if not CHROMEDRIVER_PATH:
    POSSIBLE_PATHS = [
        "/usr/local/bin/chromedriver",
        "/usr/bin/chromedriver",
        "/var/www/html/magento/pub/chromedriver/linux64/144.0.7559.31/chromedriver"
    ]
    for path in POSSIBLE_PATHS:
        if os.path.exists(path):
            CHROMEDRIVER_PATH = path
            break

if not CHROMEDRIVER_PATH:
    print(json.dumps({"success": False, "message": "Error: chromedriver not found in PATH or fallback locations."}))
    sys.exit(1)

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
        except Exception:
            pass

def log(message):
    """Log to stderr for debugging"""
    print(f"[Python] {message}", file=sys.stderr)

def is_token_valid(token):
    """Checks if JWT token is valid (not expired)"""
    try:
        parts = token.split('.')
        if len(parts) != 3:
            return False

        payload = parts[1]
        # Add padding if needed
        padding = len(payload) % 4
        if padding:
            payload += '=' * (4 - padding)

        decoded = base64.urlsafe_b64decode(payload)
        data = json.loads(decoded)

        if 'exp' in data:
            exp = data['exp']
            now = time.time()
            # Check if expired (with 5 min buffer)
            if exp > (now + 300):
                return True
            else:
                log(f"Token found but expired. Exp: {exp}, Now: {now}")
                return False
        return True # No exp field, assume valid
    except Exception as e:
        log(f"Error validating token: {e}")
        return False

def wait_for_token(driver, timeout=45):
    """Polls for VALID token in localStorage and cookies"""
    start_time = time.time()
    log(f"Polling for valid token (timeout={timeout}s)...")

    while time.time() - start_time < timeout:
        token = extract_token(driver)
        if token:
            if is_token_valid(token):
                log("Valid token found.")
                return token
            else:
                # Token exists but expired.
                pass
        time.sleep(1)

    log("Token polling timed out or no valid token found.")
    return None

def clear_browser_data(driver):
    """Clears localStorage, sessionStorage, cookies and Service Workers"""
    try:
        driver.execute_script("window.localStorage.clear();")
        driver.execute_script("window.sessionStorage.clear();")
        driver.delete_all_cookies()

        # Clear Service Workers
        driver.execute_script("""
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then(function(registrations) {
                    for(let registration of registrations) {
                        registration.unregister();
                    }
                });
            }
        """)

        log("Browser data (localStorage, cookies, SW) cleared.")
    except Exception as e:
        log(f"Error clearing browser data: {e}")

def get_token(username, password):
    cleanup_profile_locks(PROFILE_DIR)

    options = Options()
    options.add_argument("--headless")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--disable-gpu")
    options.add_argument("--window-size=1920,1080")
    options.add_argument("--remote-debugging-port=9222")
    options.add_argument("--disable-session-crashed-bubble")
    options.add_argument("--disable-infobars")
    options.add_argument(f"--user-data-dir={PROFILE_DIR}")
    options.add_argument("user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")
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
        log("Navigating to login page...")
        driver.get("https://auth.platform.trans.eu/accounts/login")

        # 2. Check if already logged in
        try:
            WebDriverWait(driver, 10).until(EC.url_contains("platform.trans.eu"))
            log("Already on platform URL, checking for valid token...")
            token = wait_for_token(driver, timeout=5)
            if token:
                return token
            else:
                log("Logged in but token expired/missing. Clearing data and forcing logout...")
                clear_browser_data(driver)
                driver.get("https://auth.platform.trans.eu/accounts/logout")
                time.sleep(5) # Increased wait
                driver.get("https://auth.platform.trans.eu/accounts/login")
        except TimeoutException:
            log("Not redirected automatically, proceeding to login form.")

        # 3. Wait for login form
        log("Waiting for login form elements...")
        wait = WebDriverWait(driver, 30)

        try:
            wait.until(EC.presence_of_element_located((By.NAME, "login")))
            time.sleep(2) # Increased stability wait

            username_input = driver.find_element(By.NAME, "login")
            driver.execute_script("arguments[0].value = '';", username_input)
            username_input.send_keys(username)

            password_input = driver.find_element(By.NAME, "password")
            driver.execute_script("arguments[0].value = '';", password_input)
            password_input.send_keys(password)

            time.sleep(1)

            submit_btn = driver.find_element(By.CSS_SELECTOR, "button[type='submit']")
            driver.execute_script("arguments[0].click();", submit_btn)
            log("Submitted login form.")

        except TimeoutException:
            log("Login form not found. Checking if we are already logged in...")
            token = wait_for_token(driver, timeout=5)
            if token: return token

            # Last resort: Clear data and reload
            log("Login form missing and no token. Clearing data and reloading...")
            clear_browser_data(driver)
            driver.refresh()
            time.sleep(5)

            # Try finding form one more time
            try:
                wait.until(EC.presence_of_element_located((By.NAME, "login")))
                # ... (would need to repeat login logic, but let's just fail with better error)
                return "Error: Login form appeared after refresh but script logic ended. Please retry."
            except:
                return "Error: Login form not found and no valid token present."

        except Exception as e:
            return f"Error interacting with login form: {str(e)}"

        # 4. Wait for redirect and token
        log("Waiting for post-login redirect...")

        try:
            WebDriverWait(driver, 45).until(
                lambda d: "accounts/login" not in d.current_url or "platform.trans.eu" in d.current_url
            )
            log(f"URL changed to: {driver.current_url}")
        except TimeoutException:
            log("Timeout waiting for URL change. Checking for MFA or errors...")

        if "mfa/auth" in driver.current_url or "Logowanie z nieznanego urządzenia" in driver.page_source:
            log("MFA Detected!")
            return "Error: MFA Required. Please run manually to authorize this device."

        # 5. Poll for token
        token = wait_for_token(driver, timeout=60)

        if token:
            return token
        else:
            debug_info = {
                "url": driver.current_url,
                "cookies": [c['name'] for c in driver.get_cookies()],
                "localStorageKeys": driver.execute_script("return Object.keys(localStorage);")
            }
            return f"Error: Valid token not found after waiting. Debug: {json.dumps(debug_info)}"

    except Exception as e:
        return f"Error: Unexpected exception: {str(e)}"
    finally:
        if driver:
            driver.quit()

def extract_token(driver):
    # 1. Direct localStorage check
    try:
        token = driver.execute_script("return localStorage.getItem('access_token');")
        if token and len(token) > 20: return token
    except: pass

    # 2. Search inside JSON objects in localStorage
    try:
        script = """
        for (var i = 0; i < localStorage.length; i++) {
            var key = localStorage.key(i);
            var value = localStorage.getItem(key);
            if (value && value.startsWith('eyJ') && value.split('.').length === 3) return value;
            try {
                var obj = JSON.parse(value);
                if (obj.access_token) return obj.access_token;
                if (obj.token) return obj.token;
            } catch(e) {}
        }
        return null;
        """
        token = driver.execute_script(script)
        if token: return token
    except: pass

    # 3. Check cookies
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
