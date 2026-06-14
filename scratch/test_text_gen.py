import sys
import os
from PIL import Image

# Set fallback Google Application Default Credentials
if 'GOOGLE_APPLICATION_CREDENTIALS' not in os.environ:
    os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = r"C:\laragon\www\mockups\storage\credentials.json"

from google import genai

def test_model(model, location):
    print(f"\nTesting model '{model}' in location '{location}'...")
    try:
        client = genai.Client(
            vertexai=True,
            project="project-3c7fb926-f021-47c6-9cc",
            location=location
        )
        response = client.models.generate_content(
            model=model,
            contents=["Hello, write a single word response."]
        )
        print(f"Success! Response: {response.text.strip()}")
        return True
    except Exception as e:
        print(f"Failed: {e}")
        return False

def test_model_with_image(model, location, img_path):
    print(f"\nTesting model '{model}' in location '{location}' with image...")
    try:
        client = genai.Client(
            vertexai=True,
            project="project-3c7fb926-f021-47c6-9cc",
            location=location
        )
        img = Image.open(img_path).convert("RGB")
        response = client.models.generate_content(
            model=model,
            contents=["Describe this image in one word.", img]
        )
        print(f"Success! Response: {response.text.strip()}")
        return True
    except Exception as e:
        print(f"Failed: {e}")
        return False

# Test combinations
locations = ["global", "us-central1"]
models = [
    "gemini-2.5-flash",
    "publishers/google/models/gemini-2.5-flash",
    "gemini-2.0-flash",
    "publishers/google/models/gemini-2.0-flash",
]

img_path = r"c:\laragon\www\mockups\jobs\job_test_real_1781266499_2157\test.png"

for loc in locations:
    for mod in models:
        test_model_with_image(mod, loc, img_path)
