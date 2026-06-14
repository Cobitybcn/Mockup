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
    
    img_path = r"c:\laragon\www\mockups\jobs\job_test_real_1781266499_2157\test.png"
    pil_img = Image.open(img_path)
    img_rgb = pil_img.convert("RGB")
    
    with open(r"c:\laragon\www\mockups\jobs\job_test_real_1781266499_2157\prompt.txt", "r", encoding="utf-8") as f:
        prompt = f.read()
        
    contents = [prompt, img_rgb]
    
    model = "publishers/google/models/gemini-3.1-flash-image"
    print(f"Calling generate_content with model {model}...")
    print(f"Image details: size={img_rgb.size}, mode={img_rgb.mode}")
    print(f"Prompt length: {len(prompt)} characters")
    
    response = client.models.generate_content(
        model=model,
        contents=contents
    )
    
    print("Response received!")
    if response.candidates:
        print(f"Candidates found: {len(response.candidates)}")
    else:
        print("No candidates in response.")
        
except Exception as e:
    import traceback
    traceback.print_exc()
    sys.exit(1)
