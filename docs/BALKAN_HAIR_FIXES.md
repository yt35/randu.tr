# Balkan Hair / Randu Admin Düzeltmeleri

Canlıya (`/home/randu.tr/public_html/admin/`) deploy edildi. Bu repodaki `patches/admin/` klasörü aynı dosyaların kopyasıdır.

## Düzeltilen sorunlar

1. **Müşteri sıralama/filtre**: UI `appointment_count` gönderiyordu, API yalnızca `total_visits` kabul ediyordu → isme düşüyordu. Alias eklendi; arama kelimeleri AND; telefon `LIKE %%` engellendi.
2. **Randevu anahtar kelime**: Rakamsız aramada `phone LIKE '%%'` tüm kayıtları getiriyordu. Düzeltildi; tenant adı (`ct.name`) üzerinden aranıyor.
3. **Müşteri geçmişi**: Detaylı modal — randevu listesi, hizmetler, tutar, ödeme yöntemi. Ödeme renkleri: nakit sarı, havale pembe, alınmadı kırmızı. Modal içinde arama, ödeme türü filtresi, harcama/tarih sıralaması.
4. **Fiyat aralıklı hizmetler**: Oluşturma/tamamlama/güncellemede girilen tutar `appointment_services.final_price` ve `total_price` ile senkron; tek aralık hizmette toplam farkı `final_price`’a yazma; tamamlayınca min fiyata dönme engellendi.
5. **Defter ödeme renkleri**: Alınmadı daha koyu kırmızı (`#b91c1c` pill); nakit sarı, havale pembe — geçmiş ile aynı dil.
6. **Bekleme listesi B**: Tercih edilen saatte defter saat etiketi + dolu/boş kartlarda `B` (D/+ gibi); bekleme listesi satırında da B ve not.
7. **Ürün barkod görünürlüğü**: Ürün kartına tıklanınca hızlı işlem modalında barkod varsa JsBarcode görseli + kod; düzenleme modalında da önizleme.
8. **Aralıklı fiyat → toplam**: Tamamla/düzenle “Alınan tutar” yazılınca toplam güncellenmiyordu (create formundaki eski range input okunuyordu). Modal içinde scope + oninput; create toplamı `final_price` kalıntısından değil seçimden hesaplanıyor; `price_min=0` için PHP `!empty` kırığı düzeltildi.

## Deploy edilen dosyalar

- `assets/js/customers.js`, `daily_view.js`, `appointments.js`
- `assets/css/daily_view.css`
- `api/customers/*` (history/list — tur 1)
- `api/appointments/{create,complete,update,list}.php`
- `api/daily_view/{create,complete,update}.php`
- `products.php` (barkod görsel önizleme)
