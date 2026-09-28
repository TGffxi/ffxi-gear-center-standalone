from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
SERVICES = (ROOT / 'payload/scripts/gear-center-services.sh').read_text(encoding='utf-8')
OPENER = (ROOT / 'payload/scripts/open-gear-center.sh').read_text(encoding='utf-8')
INSTALLER = (ROOT / 'install-1.0.sh').read_text(encoding='utf-8')
README = (ROOT / 'README.md').read_text(encoding='utf-8')
RELEASE = json.loads((ROOT / 'RELEASE.json').read_text(encoding='utf-8'))
ACCEPTANCE = (ROOT / 'payload/app/bin/v1-acceptance.sh').read_text(encoding='utf-8')


def test_release_is_standalone_102():
    assert RELEASE['version'] == '1.0.2'
    assert RELEASE['release_status'] == 'PUBLIC-STANDALONE'
    assert RELEASE['standalone']['ffxi_center_required'] is False


def test_services_expose_real_manual_lifecycle():
    assert 'standalone_owner' in SERVICES
    assert '_standalone-owner' in SERVICES
    assert "manual_start(){ echo 'Direkter Start ist deaktiviert" not in SERVICES
    assert "restart) echo 'Direkter Restart ist deaktiviert" not in SERVICES
    assert 'standalone.owner.pid' in SERVICES


def test_open_path_is_owner_generic_not_center_only():
    assert 'Gear Center ist nur verfügbar, während FFXI Center läuft.' not in OPENER
    assert 'owner_active' in OPENER


def test_installer_treats_ffxi_center_as_optional():
    assert 'CENTER_AVAILABLE=0' in INSTALLER
    assert 'FFXI Center nicht gefunden; Standalone-Lifecycle wird installiert' in INSTALLER
    assert 'for f in "$CENTER" "$BOOT" "$POINTS"' not in INSTALLER
    assert 'if [[ "$CENTER_AVAILABLE" -eq 1 ]]' in INSTALLER



def test_acceptance_matches_standalone_lifecycle():
    assert 'FFXI Center ist einziger normaler Service-Starter' not in ACCEPTANCE
    assert 'Standalone-Lifecycle' in ACCEPTANCE
    assert 'standalone.owner.pid' in ACCEPTANCE


def test_readme_leads_with_standalone_usage():
    assert 'Standalone-Betrieb' in README
    assert 'gear-center-services.sh start' in README
    assert 'FFXI Center ist optional' in README


if __name__ == '__main__':
    tests = [
        test_release_is_standalone_102,
        test_services_expose_real_manual_lifecycle,
        test_open_path_is_owner_generic_not_center_only,
        test_installer_treats_ffxi_center_as_optional,
        test_acceptance_matches_standalone_lifecycle,
        test_readme_leads_with_standalone_usage,
    ]
    for test in tests:
        test()
    print(f'PASS: standalone public contract ({len(tests)} groups)')
