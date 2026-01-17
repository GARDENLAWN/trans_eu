    # 1. Pobierz kod
    composer update gardenlawn/transeu gardenlawn/core # lub git pull

    # 2. Aktualizacja Magento
    bin/magento setup:upgrade
    bin/magento cache:clean config

    # 3. Konfiguracja w Panelu Admina (WAŻNE!)
    # Wejdź w Stores > Config > GardenLawn > Trans.eu
    # Wpisz Login i Hasło.
    # WYCZYŚĆ pole "Manual Token".
    # Zapisz.

    # 4. Aktualizacja skryptów systemowych (zgodnie z FILES.md)
    # Skopiuj nowy monitor_services.sh do /root/scripts/
    # Skopiuj nowy magento-consumer.conf do /etc/supervisord.d/ i zrób update.
    