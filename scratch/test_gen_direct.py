import sys
import os
from PIL import Image

# Set fallback Google Application Default Credentials
if 'GOOGLE_APPLICATION_CREDENTIALS' not in os.environ:
    os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = r"C:\laragon\www\mockups\storage\credentials.json"

from google import genai

try:
    print("Initializing client...")
    client = genai.Client(
        vertexai=True,
        project="project-3c7fb926-f021-47c6-9cc",
        location="global"
    )
    
    import time
    import random
    from google.genai.errors import ClientError

    def call_with_retry(fn, max_retries=5):
        for attempt in range(max_retries):
            try:
                return fn()
            except ClientError as e:
                if "429" in str(e) or "exhausted" in str(e).lower() and attempt < max_retries - 1:
                    sleep_time = (2 ** attempt) * 5 + random.uniform(1, 5)
                    print(f"Rate limited (429). Retrying in {sleep_time:.2f} seconds...")
                    time.sleep(sleep_time)
                    continue
                raise e

    model = "publishers/google/models/gemini-3.1-flash-image"
    # Experiment 3: Length limit testing
    print("\n--- Experiment 3: Length Limit Testing ---")
    mock_img = Image.new("RGB", (100, 100), color=(100, 100, 100))
    
    part_a = """ACTIVE ROOT ARTWORK DIRECTIVES:
Create a premium close-up front photograph of this attached artwork.
The artwork is resting on the floor against the wall.
The painting is perfectly stretched over a wooden stretcher frame.
The artwork is fully visible.
Illuminate the product with studio lighting: soft fill light from both sides and directional highlights, with HDR-like tonal separation and flawless edges.
No logos, text, or visible branding.
The entire product must be sharp and in focus, with no background blur, showing details of incisions, textures, brushstrokes, palette knife work, and paint blocks.
Respect the original artwork: do not redraw it, do not change its composition, do not artistically modify its colors, and do not alter the artist's brushwork or textures.
The real dimensions belong only to the artwork, not to the photo or background."""

    part_b = """Medidas reales de la obra, no de la foto ni del fondo:
The provided dimensions refer ONLY to the physical artwork itself: 100 cm wide x 80 cm high. They do not refer to the full photograph, background, table, wall, support board, margins, or surrounding objects. Use these dimensions to preserve the real artwork proportion. Required output orientation: landscape. Required aspect ratio: 1.25. Stretcher/support depth of the artwork: 2 cm."""

    part_c = """ARTIST NOTES:
Test curatorial energy and geometric structure."""

    # Test B + C with '1.25' replaced by '5:4'
    part_b_mod = part_b.replace("1.25", "5:4")
    bc_mod = part_b_mod + "\n\n" + part_c
    print("\n--- Testing B + C modified (1.25 -> 5:4) ---")
    try:
        response = call_with_retry(lambda: client.models.generate_content(
            model=model,
            contents=[bc_mod, mock_img]
        ))
        print("B + C modified Succeeded!")
    except Exception as e:
        print("B + C modified Failed:", e)







        
except Exception as e:
    import traceback
    traceback.print_exc()
    sys.exit(1)
