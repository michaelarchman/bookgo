# BookGo — Plan wtyczki

Nowa wtyczka rezerwacji wizyt oparta na architekturze esdavida-booking.
Tworzona iteracyjnie — po jednej funkcjonalności na raz.

---

## Stos technologiczny

- PHP 7.4+ / PSR-4 autoloader (bez Composer)
- WordPress 5.0+
- WooCommerce 4.0+
- jQuery (dostarczany przez WordPress)
- FullCalendar v6 (CDN) — widok kalendarza admina

---

## Architektura katalogów

```
bookgo/
├── bookgo.php                          ← główny plik wtyczki
├── bookgo.md                           ← ten plik
├── assets/
│   ├── booking-form.js                 ← frontend: wybór terminu
│   ├── booking-form.css
│   ├── product-slots.js                ← admin: dodawanie terminów do produktu
│   └── product-slots.css
└── includes/
    ├── WooCommerce/
    │   ├── BookingProductType.php      ← typ produktu "bookgo" (proste, nie variable)
    │   └── Hooks.php                   ← integracja WooCommerce (koszyk, checkout redirect)
    ├── Admin/
    │   ├── AppointmentsCalendar.php    ← widok kalendarza (FullCalendar)
    │   └── ProductSlots.php            ← meta box: terminy wizyty
    ├── Api/
    │   └── AppointmentsApi.php         ← REST API: /ping, /available, /dates, /book
    ├── Service/
    │   └── BookingService.php          ← logika: dostępne sloty, liczenie rezerwacji
    └── Frontend/
        ├── BookingForm.php             ← shortcode [bookgo_form]
        └── BookingCheckout.php         ← shortcode [bookgo_checkout]
```

---

## Typ produktu

- Nazwa: **bookgo**
- Etykieta: „Wizyta (BookGo)"
- Rozszerza: `WC_Product` (prosty produkt, NIE variable)
- Cechy: `is_virtual() = true`, `is_downloadable() = false`
- Meta produktu:
  - `_bookgo_duration` — czas trwania wizyty w minutach (liczba, domyślnie 60)
  - `_bookgo_slots` — lista terminów (tablica: date, time, capacity)

---

## Baza danych

Tabela `wp_bookgo_appointments` (tworzona przy aktywacji):

| Kolumna     | Typ                | Opis                        |
|-------------|--------------------|-----------------------------|
| id          | BIGINT UNSIGNED PK |                             |
| user_id     | BIGINT UNSIGNED    | nullable                    |
| product_id  | BIGINT UNSIGNED    | ID produktu WooCommerce     |
| start_time  | DATETIME           | start wizyty (timezone WP)  |
| end_time    | DATETIME           | koniec wizyty               |
| status      | VARCHAR(20)        | pending / confirmed         |
| notes       | TEXT               | nullable                    |
| created_at  | DATETIME           | auto                        |

---

## REST API (`bookgo/v1`)

| Metoda | Endpoint       | Opis                                               |
|--------|----------------|----------------------------------------------------|
| GET    | /ping          | health check                                       |
| GET    | /available     | dostępne godziny dla produktu i daty               |
| GET    | /dates         | dostępne daty (z wolnymi miejscami) dla produktu   |
| POST   | /book          | zapis rezerwacji do tabeli wp_bookgo_appointments  |

---

## Shortcodes

| Shortcode           | Opis                                        |
|---------------------|---------------------------------------------|
| `[bookgo_form]`     | formularz wyboru terminu na stronie produktu|
| `[bookgo_checkout]` | potwierdzenie rezerwacji + formularz danych |

---

## Admin Stories (TODO)

- [x] **Krok 1** — Szkielet wtyczki: `bookgo.php`, autoloader, aktywacja DB
- [x] **Krok 2** — Typ produktu WooCommerce: `BookingProductType.php`
- [x] **Krok 3** — Meta box „Terminy wizyty": `ProductSlots.php` + `product-slots.{js,css}`
- [x] **Krok 4** — Logika slotów: `BookingService.php`
- [x] **Krok 5** — REST API: `AppointmentsApi.php`
- [x] **Krok 6** — Frontend formularz: `BookingForm.php` + `booking-form.{js,css}`
- [x] **Krok 7** — Checkout: `Hooks.php` (integracja WooCommerce)
- [x] **Krok 8** — Kalendarz admina: `AppointmentsCalendar.php`
- [ ] **Krok 9** — Testy end-to-end i szlif

---

## Client Stories (wymagania)

1. Użytkownik widzi dwa produkty: „Konsultacja indywidualna pierwsza - online"
   i „Konsultacja indywidualna pierwsza - Warszawa"
2. Przy zakupie widzi listę dostępnych terminów (data + godzina) zdefiniowanych przez admina
3. Wybiera termin → płaci → w zamówieniu zapisane są: Data wizyty, Godzina wizyty
4. Każdy użytkownik może kupić wizytę (brak ograniczeń na login)
5. Płatność i e-maile potwierdzające obsługuje WooCommerce standardowo

---

## Admin Stories (wymagania)

1. Administrator widzi typ produktu „Wizyta (BookGo)" przy tworzeniu produktu
2. W edycji produktu ustawia cenę oraz czas trwania wizyty (minuty)
3. W edycji produktu dodaje listę terminów: data, godzina, liczba miejsc
4. Widzi kalendarz zarezerwowanych wizyt (FullCalendar, kliknięcie otwiera zamówienie)

---

## Flow rezerwacji (klient)

```
[bookgo_form] na stronie produktu
    ↓
Załaduj daty z GET /dates?product_id=X
    ↓
Kliknij datę → załaduj godziny z GET /available?product_id=X&date=Y
    ↓
Kliknij godzinę → aktywuj przycisk „Zarezerwuj"
    ↓
Kliknij „Zarezerwuj"
    → /?add-to-cart=PRODUCT_ID&booking_date=Y&booking_time=Z
    ↓
Standardowy koszyk WooCommerce
    ↓
Standardowy checkout WooCommerce
    ↓
Zamówienie WooCommerce (meta: Data wizyty, Godzina wizyty)
    ↓
E-mail potwierdzający — standardowy WooCommerce
```

---

## Kluczowe decyzje architektoniczne

- Typ produktu **prosty** (nie variable) — admin ustawia jedną cenę i listę terminów
- Terminy są **per-produkt**, nie z globalnego harmonogramu tygodniowego
- Walidacja zajętości slotu uwzględnia **capacity** (liczba miejsc)
- Checkout, płatności i e-maile — **standardowy WooCommerce**, bez nadpisywania
- Data i godzina wizyty doklejane do zamówienia jako meta (`Data wizyty`, `Godzina wizyty`)
- Konflikty rezerwacji sprawdzane w zamówieniach WooCommerce (meta `Data wizyty` + `Godzina wizyty`)

---

## Notatki

- Shortcode `[bookgo_form]` automatycznie wykrywa produkt ze strony, ale przyjmuje też `product_id` jako atrybut
- `_bookgo_slots` przechowywana jako tablica PHPa w post meta (serialize/unserialize przez WordPress)
- Timezone wszystkich dat/godzin: `wp_timezone()` (ustawienia WordPressa)
