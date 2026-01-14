# GardenLawn Trans.eu Integration

Moduł integrujący sklep Magento 2 z platformą logistyczną Trans.eu. Umożliwia autoryzację OAuth2 oraz pobieranie tokenów dostępu niezbędnych do komunikacji z API Trans.eu.

## Funkcjonalności

*   **Autoryzacja OAuth2**: Pełny proces autoryzacji (Authorization Code Grant).
*   **Zarządzanie tokenami**: Automatyczne pobieranie, zapisywanie i odświeżanie tokenów dostępu (`access_token`) oraz odświeżania (`refresh_token`).
*   **Bezpieczeństwo**: Przechowywanie kluczy API i tokenów w zaszyfrowanej formie w konfiguracji Magento.
*   **Narzędzia testowe**: Wbudowana strona w panelu admina do weryfikacji połączenia i stanu tokenów.

## Wymagania

*   Magento 2.4.x
*   PHP 7.4+ / 8.x
*   Konto deweloperskie na platformie Trans.eu (Client ID, Client Secret, API Key).

## Instalacja

1.  Skopiuj pliki modułu do katalogu `app/code/GardenLawn/TransEu`.
2.  Uruchom polecenia instalacyjne:
    ```bash
    bin/magento module:enable GardenLawn_TransEu
    bin/magento setup:upgrade
    bin/magento cache:clean
    ```

## Konfiguracja

1.  Zaloguj się do panelu administracyjnego Magento.
2.  Przejdź do **Stores > Configuration > GardenLawn > Trans.eu Integration**.
3.  Wprowadź dane otrzymane od Trans.eu:
    *   **Client ID**
    *   **Client Secret**
    *   **API Key**
    *   **TransId** (opcjonalnie, dla celów informacyjnych)
4.  Upewnij się, że adresy URL są poprawne (domyślnie dla środowiska produkcyjnego):
    *   Auth URL: `https://auth.platform.trans.eu`
    *   API URL: `https://api.platform.trans.eu`
5.  Skonfiguruj **Redirect URI**:
    *   Wpisz adres: `https://twoja-domena.pl/trans_eu` (lub `https://twoja-domena.pl/delivery/index/index` jeśli nie używasz routingu `trans_eu`).
    *   **Ważne:** Ten sam adres musi być dodany w ustawieniach Twojej aplikacji w panelu deweloperskim Trans.eu.
6.  Zapisz konfigurację (**Save Config**).

## Użycie (Autoryzacja)

1.  Po zapisaniu konfiguracji, w sekcji **Trans.eu Integration** kliknij przycisk **Authorize**.
2.  Zostaniesz przekierowany na stronę logowania Trans.eu.
3.  Zaloguj się i potwierdź dostęp.
4.  Po pomyślnej autoryzacji zostaniesz przekierowany z powrotem do sklepu, a tokeny zostaną zapisane w tle.

## Weryfikacja i Testy

Moduł udostępnia narzędzie do sprawdzania stanu połączenia:

1.  W panelu admina przejdź do **Sales > Operations > Trans.eu API Test**.
2.  Strona wyświetli:
    *   Status konfiguracji.
    *   Aktualnie zapisane tokeny i czas ich wygaśnięcia.
    *   Wynik próbnego pobrania tokenu (co automatycznie przetestuje mechanizm `refreshToken`, jeśli token wygasł).

Alternatywnie, możesz użyć skryptu w przeglądarce (jeśli został utworzony w `pub/`):
`https://twoja-domena.pl/test_trans_eu.php`

## Logi

Szczegółowe logi z procesu wymiany tokenów znajdują się w pliku:
`var/log/system.log` (lub `debug.log` w zależności od konfiguracji loggera).
Szukaj wpisów rozpoczynających się od `Trans.eu`.
