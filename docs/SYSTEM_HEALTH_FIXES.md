# Sistem sağlık düzeltmeleri (kullanıcıya etkisiz)

Canlıya deploy edildi. Yedek: `/home/randu.tr/public_html/_safe_bak_*`

1. **Admin header pending ödeme SQL** — UNION `ORDER BY created_at` ambiguous → alt sorgu.
2. **Müşteri paneli iptal SQL** — `tenant_id` → `a.tenant_id`.
3. **Log gürültüsü** — `get_available_times` varsayılan kapalı; gallery debug `error_log` kaldırıldı; JS `logDebug`/`dvDebugLog` sadece `RANDU_DEBUG=1` / `localStorage.RANDU_DEBUG=1`.
4. **Sitemap** — eksik tablo/kolon sessiz skip; slug bağımlılığı kaldırıldı.
5. **can_access_page** — “menüde yok” INFO spam kaldırıldı.
6. **appointment.php** — `$appt_base_url` her iki dalda tanımlı.
7. Eski `_bak_20261015_*` temizlendi.
