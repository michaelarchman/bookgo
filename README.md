# BookGo — Plugin rezerwacji wizyt dla WooCommerce

BookGo rozszerza WooCommerce o dedykowany typ produktu „Wizyta", umożliwiając sprzedaż terminów na usługi (konsultacje, sesje, spotkania). Klient wybiera datę i godzinę bezpośrednio na stronie produktu, a cała transakcja przebiega standardowym przepływem WooCommerce.

## Wymagania

- PHP 7.4+
- WordPress 5.0+
- WooCommerce 4.0+

## Instalacja

1. Skopiuj folder `bookgo` do katalogu `wp-content/plugins/`.
2. Aktywuj wtyczkę w panelu WordPress.
3. Upewnij się, że WooCommerce jest zainstalowany i aktywny.

## Jak zacząć

### 1. Utwórz produkt typu „Wizyta"

1. Przejdź do **Produkty → Dodaj nowy**.
2. W sekcji „Dane produktu" wybierz typ **Wizyta (BookGo)**.
3. W zakładce **Sloty BookGo** ustaw czas trwania wizyty (minuty) i dodaj dostępne terminy:
   - **Data** — dzień dostępności (YYYY-MM-DD)
   - **Godzina** — godzina slotu (co 30 minut)
   - **Pojemność** — ile równoległych rezerwacji dopuszcza slot
4. Opublikuj produkt.

### 2. Dodaj formularz na stronie produktu

Umieść shortcode na stronie lub w treści produktu:

```
[bookgo_form]
```

Opcjonalnie wskaż konkretny produkt:

```
[bookgo_form product_id="123"]
```

Formularz automatycznie pobiera dostępne daty i godziny, a po wyborze terminu przekierowuje klienta do koszyka WooCommerce.

### 3. Zarządzaj rezerwacjami

Przejdź do **BookGo → Kalendarz wizyt**, aby zobaczyć wszystkie zarezerwowane terminy w widoku kalendarza (miesiąc / tydzień / dzień). Kliknięcie wizyty przenosi bezpośrednio do zamówienia WooCommerce.

Kolory odpowiadają statusom zamówień:
- Żółty — oczekujące
- Niebieski — w realizacji
- Pomarańczowy — wstrzymane
- Zielony — zakończone

## REST API

Publiczne endpointy do sprawdzania dostępności:

| Endpoint | Opis |
|---|---|
| `GET /wp-json/bookgo/v1/ping` | Health check |
| `GET /wp-json/bookgo/v1/dates?product_id=X` | Lista dostępnych dat dla produktu |
| `GET /wp-json/bookgo/v1/available?product_id=X&date=YYYY-MM-DD` | Dostępne godziny dla wybranej daty |

## Struktura plików

```
bookgo/
├── bookgo.php                        # Główny plik wtyczki, autoloader, aktywacja
├── assets/
│   ├── booking-form.js               # Logika formularza (wybór daty i godziny)
│   ├── booking-form.css
│   ├── product-slots.js              # Zarządzanie slotami w panelu admina
│   └── product-slots.css
└── includes/
    ├── WooCommerce/
    │   ├── BookingProductType.php    # Rejestracja typu produktu
    │   └── Hooks.php                 # Integracja z koszykiem i zamówieniami
    ├── Admin/
    │   ├── AppointmentsCalendar.php  # Widok kalendarza (FullCalendar v6)
    │   └── ProductSlots.php          # Meta box slotów na stronie produktu
    ├── Api/
    │   └── AppointmentsApi.php       # Endpointy REST
    ├── Service/
    │   └── BookingService.php        # Logika dostępności i rezerwacji
    └── Frontend/
        └── BookingForm.php           # Shortcode [bookgo_form]
```

## Przepływ rezerwacji

1. Klient wchodzi na stronę produktu z formularzem `[bookgo_form]`.
2. Formularz pobiera dostępne daty przez REST API.
3. Po wyborze daty pobierane są dostępne godziny.
4. Klient wybiera termin i klika „Zarezerwuj".
5. Produkt trafia do koszyka z przypisaną datą i godziną.
6. Standardowy checkout WooCommerce — płatność i potwierdzenie mailem.
7. Data i godzina wizyty zapisywane są w metadanych zamówienia.

## Notatki techniczne

- Strefy czasowe oparte na ustawieniu WordPress (`wp_timezone()`).
- Przeszłe terminy są automatycznie pomijane.
- Detekcja konfliktów sprawdza aktywne zamówienia (pending, processing, on-hold, completed) i porównuje z pojemnością slotu.
- Brak zależności od Composera — autoloader PSR-4 zaimplementowany ręcznie.
