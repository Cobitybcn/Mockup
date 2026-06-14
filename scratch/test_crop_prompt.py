import sys
import os
from PIL import Image

# Set fallback Google Application Default Credentials
if 'GOOGLE_APPLICATION_CREDENTIALS' not in os.environ:
    os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = r"C:\laragon\www\mockups\storage\credentials.json"

from google import genai

try:
    import httpx
    client = genai.Client(
        vertexai=True,
        project="project-3c7fb926-f021-47c6-9cc",
        location="global",
        http_options={'httpx_client': httpx.Client(timeout=600.0)}
    )
    
    img_path = r"C:\laragon\www\mockups\jobs\job_1781208900_9995\main_artwork.jpg"
    pil_img = Image.open(img_path)
    img_rgb = pil_img.convert("RGB")
    
    prompt = """
Generate a clean, high-resolution front-facing close-up of ONLY the painting itself, filling the entire frame.
Remove all background elements, including walls, floor, frames, easels, stands, and shadows.
The output image must contain only the canvas and the painting, shown flat, centered, and occupying 100% of the image space from edge to edge.
Do not redraw, repaint, or modify the composition of the original painting.
"""
    
    contents = [prompt, img_rgb]
    
    model = "publishers/google/models/gemini-3.1-flash-image"
    print(f"Calling generate_content with model {model}...")
    
    response = client.models.generate_content(
        model=model,
        contents=contents
    )
    
    print("Response received!")
    img_found = False
    if response.candidates:
        for part in response.candidates[0].content.parts:
            if part.inline_data:
                img = part.as_image()
                output_path = r"C:\laragon\www\mockups\scratch\test_enhanced_root.png"
                img.save(output_path)
                print(f"SUCCESS: Saved generated image to {output_path}")
                img_found = True
                break
        if not img_found:
            print("No image returned in parts.")
    else:
        print("No candidates in response.")
        
except Exception as e:
    import traceback
    traceback.print_exc()
    sys.exit(1)
