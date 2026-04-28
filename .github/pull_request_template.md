## Ozet

<!-- Bu PR neyi degistiriyor? -->

## Kontrol listesi

- [ ] `composer run-script test` geciyor
- [ ] `composer run-script lint` (Pint `--test`) geciyor
- [ ] `composer run-script analyse` (PHPStan / Larastan; filter-kit for bkz. `phpstan.neon.dist` and `packages/RULES/06`) geciyor
- [ ] `CHANGELOG.md` updated (if user-facing or API-impacting changes exist)
- [ ] Usagedan kaldirma varsa: `@deprecated` + CHANGELOG + uyari (bkz. ekip RULES `05-deprecation`)
- [ ] If outward behavior/public API changed, related guard/contract tests were updated or added
