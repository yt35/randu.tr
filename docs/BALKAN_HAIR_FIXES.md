# Balkan Hair / Randu Admin Düzeltmeleri

Canlıya (`/home/randu.tr/public_html/admin/`) deploy edildi. Bu repodaki `patches/admin/` klasörü aynı dosyaların kopyasıdır.

## Düzeltilen sorunlar

1. **Müşteri sıralama/filtre**: UI `appointment_count` gönderiyordu, API yalnızca `total_visits` kabul ediyordu → isme düşüyordu. Alias eklendi; arama kelimeleri AND; telefon `LIKE %%` engellendi.
2. **Randevu anahtar kelime**: Rakamsız aramada `phone LIKE '%%'` tüm kayıtları getiriyordu. Düzeltildi; tenant adı (`ct.name`) üzerinden aranıyor.
3. **Müşteri geçmişi**: Geçmiş modalı yalnızca özet gösteriyordu. Artık randevu listesi, hizmetler, tutar, ödeme yöntemi/durumu geliyor.
4. **Fiyat aralıklı hizmetler**: Oluşturma/tamamlama/güncellemede girilen tutar `appointment_services.final_price` ve `total_price` ile senkron tutuluyor; tamamlayınca min fiyata dönme engellendi.
