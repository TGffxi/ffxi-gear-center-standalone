from __future__ import annotations
import importlib.util, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
P=ROOT/'payload/center/patch_ffxi_center.py'
spec=importlib.util.spec_from_file_location('center_patch',P); mod=importlib.util.module_from_spec(spec); assert spec and spec.loader; spec.loader.exec_module(mod)

SAMPLE='''import os\nimport subprocess\nimport tkinter as tk\nfrom pathlib import Path\nSCRIPTS=Path("/tmp")\nASHITA=Path("/tmp")\nHB_GEARLAB=Path("/tmp")\nHB_METRICS=Path("/tmp")\nHB_GEARLAB_CAPTURE = ASHITA / "addons/gearlab_capture"\nHB_GEARLAB_CAPTURE_CFG = ASHITA / "config/addons/gearlab_capture"\nHB_STARTERS = {\n    "FFXI / Ashita": SCRIPTS / "start-ffxi-ashita.sh",\n    "Gear Center": SCRIPTS / "old-gear-center.sh",\n    "FFXI Center": SCRIPTS / "start-ffxi-center.sh",\n}\nSTARTERS = {\n    "FFXI / Ashita": SCRIPTS / "start-ffxi-ashita.sh",\n    "Gearplaner 0.8": SCRIPTS / "old.sh",\n}\nclass Center(tk.Tk):\n    def x(self):\n        add("gearlab", "04 Gear / Analyse", "FFXI Gear Lab", f\'\'\'\nTheorie bleibt.\n\'\'\', ["/addon load gearlab_capture", "/glcapture status", "/glcapture on", "/glcapture off", "/glcapture mark"], HB_GEARLAB)\n\n        add("capture", "04 Gear / Analyse", "Gear Lab Live Capture – Befehle", \'\'\'\n/addon load gearlab_capture\n/glcapture status\n/glcapture on\n\'\'\', ["/addon load gearlab_capture", "/glcapture status"], HB_GEARLAB_CAPTURE_CFG)\n\n        add("metrics", "04 Gear / Analyse", "Metrics – Kontroll-/Vergleichspfad", \'\'\'\nMetrics bleibt als unabhängiger read-only Kontrollpfad neben Gear Labs Live Capture erhalten.\n\'\'\', ["/addon load metrics"], HB_METRICS)\n    def old_capture_command(self):\n        return '/addon load gearlab_capture /glcapture status'\n\nif __name__ == "__main__":\n    Center().mainloop()\n'''

def main():
    with tempfile.TemporaryDirectory() as td:
        p=Path(td)/'ffxi_center.py'; p.write_text(SAMPLE)
        r=mod.patch(p); s=p.read_text()
        assert r['starter_dictionaries']==2
        assert r['direct_lifecycle_blocks']==1
        assert s.count('"Gear Center":')==2
        assert 'Gearplaner 0.8' not in s
        assert '/glcapture' not in s and 'gearlab_capture' not in s
        assert 'Gear Lab Live Capture' not in s
        assert s.count('# GEAR_CENTER_LIFECYCLE_OWNER_BEGIN')==1
        assert '[str(services), "acquire", _GEAR_CENTER_LIFECYCLE_TOKEN, str(os.getpid())]' in s
        assert '[str(services), "release", _GEAR_CENTER_LIFECYCLE_TOKEN]' in s
        assert '_gear_center_lifecycle_acquire()\n    try:\n        Center().mainloop()\n    finally:\n        _gear_center_lifecycle_release()' in s
        compile(s,str(p),'exec')
        # Applying the patch again must not duplicate lifecycle or starter entries.
        r2=mod.patch(p); s2=p.read_text()
        assert r2['direct_lifecycle_blocks']==1
        assert s2.count('# GEAR_CENTER_LIFECYCLE_OWNER_BEGIN')==1
        assert s2.count('"Gear Center":')==2
        compile(s2,str(p),'exec')
    print('PASS: FFXI Center direct process owns lifecycle; patch idempotent; retired capture removed')
if __name__=='__main__': main()
