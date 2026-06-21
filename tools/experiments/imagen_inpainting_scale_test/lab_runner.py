import os
import sys
import json
import time
import argparse
import random
import io
from PIL import Image

# Set fallback Google Application Default Credentials
if 'GOOGLE_APPLICATION_CREDENTIALS' not in os.environ:
    fallback_adc = r"C:\laragon\www\mockups\storage\credentials.json"
    if os.path.exists(fallback_adc):
        os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = fallback_adc

from google import genai
from google.genai import types
from google.genai.errors import ClientError
from google.auth.exceptions import TransportError

# Matrix functions for perspective transform
def solve_linear_system(A, b):
    n = len(A)
    for i in range(n):
        max_el = abs(A[i][i])
        max_row = i
        for k in range(i + 1, n):
            if abs(A[k][i]) > max_el:
                max_el = abs(A[k][i])
                max_row = k
        for k in range(i, n):
            A[max_row][k], A[i][k] = A[i][k], A[max_row][k]
        b[max_row], b[i] = b[i], b[max_row]
        
        pivot = A[i][i]
        if abs(pivot) < 1e-10:
            continue
        for k in range(i, n):
            A[i][k] /= pivot
        b[i] /= pivot
        
        for k in range(i + 1, n):
            factor = A[k][i]
            for j in range(i, n):
                A[k][j] -= factor * A[i][j]
            b[k] -= factor * b[i]
            
    x = [0.0] * n
    for i in range(n - 1, -1, -1):
        x[i] = b[i]
        for k in range(i + 1, n):
            x[i] -= A[i][k] * x[k]
    return x

def find_coeffs(pa, pb):
    matrix = []
    for p1, p2 in zip(pa, pb):
        matrix.append([p1[0], p1[1], 1, 0, 0, 0, -p2[0]*p1[0], -p2[0]*p1[1]])
        matrix.append([0, 0, 0, p1[0], p1[1], 1, -p2[1]*p1[0], -p2[1]*p1[1]])
    
    y = []
    for p in pb:
        y.append(p[0])
        y.append(p[1])
        
    return solve_linear_system(matrix, y)

def get_client():
    import httpx
    project_id = os.environ.get('VERTEX_PROJECT_ID', 'project-3c7fb926-f021-47c6-9cc')
    return genai.Client(
        vertexai=True,
        project=project_id,
        location="global",
        http_options={'httpx_client': httpx.Client(timeout=600.0)}
    )

def call_with_retry(client_call_fn, max_retries=5):
    last_error = None
    for attempt in range(max_retries):
        try:
            return client_call_fn()
        except ClientError as e:
            is_rate_limit = False
            if hasattr(e, 'status_code') and e.status_code == 429:
                is_rate_limit = True
            elif "429" in str(e) or "exhausted" in str(e).lower():
                is_rate_limit = True
                
            if is_rate_limit:
                if attempt < max_retries - 1:
                    sleep_time = min(24, (2 ** attempt) * 3) + random.uniform(0.5, 2)
                    print(f"Rate limited (429). Retrying in {sleep_time:.2f} seconds...", file=sys.stderr)
                    time.sleep(sleep_time)
                    last_error = e
                    continue
                else:
                    raise e
            else:
                raise e
        except TransportError as e:
            if attempt < max_retries - 1:
                sleep_time = (2 ** attempt) * 5 + random.uniform(1, 5)
                print(f"Transport error. Retrying in {sleep_time:.2f} seconds...", file=sys.stderr)
                time.sleep(sleep_time)
                last_error = e
                continue
            raise e
        except Exception as e:
            message = str(e).lower()
            is_transient = ("winerror 10013" in message or "connection" in message or "timeout" in message)
            if is_transient and attempt < max_retries - 1:
                sleep_time = (2 ** attempt) * 5 + random.uniform(1, 5)
                print(f"Network error. Retrying in {sleep_time:.2f} seconds...", file=sys.stderr)
                time.sleep(sleep_time)
                last_error = e
                continue
            raise e
    if last_error:
        raise last_error

def main():
    parser = argparse.ArgumentParser(description="Imagen Inpainting Scale Test Lab Runner")
    parser.add_argument("--artwork", type=str, required=True, help="Path to artwork image")
    parser.add_argument("--camera", type=str, required=True, help="Camera viewpoint")
    parser.add_argument("--scene", type=str, required=True, help="Scene environment type")
    parser.add_argument("--run-id", type=str, required=True, help="Unique identifier for run")
    parser.add_argument("--output-dir", type=str, required=True, help="Directory to save experimental results")
    parser.add_argument("--width-cm", type=float, default=100.0, help="Physical width of artwork in cm")
    parser.add_argument("--height-cm", type=float, default=120.0, help="Physical height of artwork in cm")
    parser.add_argument("--model", type=str, default="imagen-3.0-capability-001", help="Vertex AI model name")
    
    args = parser.parse_args()
    
    # 1. Load and conversion
    if not os.path.isfile(args.artwork):
        print(f"Error: Artwork file not found: {args.artwork}", file=sys.stderr)
        sys.exit(1)
        
    os.makedirs(args.output_dir, exist_ok=True)
    
    # Define filenames
    prefix = f"{args.camera}_{args.scene}_{args.run_id}"
    base_path = os.path.join(args.output_dir, f"{prefix}_base.png")
    mask_path = os.path.join(args.output_dir, f"{prefix}_mask.png")
    result_path = os.path.join(args.output_dir, f"{prefix}_result.png")
    meta_path = os.path.join(args.output_dir, f"{prefix}_metadata.json")
    
    metadata = {
        "artwork": args.artwork,
        "camera": args.camera,
        "scene": args.scene,
        "run_id": args.run_id,
        "model": args.model,
        "width_cm": args.width_cm,
        "height_cm": args.height_cm,
        "prompt": "",
        "status": "pending",
        "duration_seconds": 0,
        "error": None
    }
    
    try:
        pil_img = Image.open(args.artwork).convert("RGBA")
        w, h = pil_img.size
        
        # 2. Camera perspective skew transformation
        # Calculate skew factors based on camera perspective
        camera = args.camera
        if camera == "left_soft":
            pa = [
                (0, 0),
                (0, h),
                (int(w * 0.85), int(h * 0.95)),
                (int(w * 0.85), int(h * 0.05))
            ]
            target_size = (int(w * 0.85), h)
        elif camera == "right_soft":
            pa = [
                (int(w * 0.15), int(h * 0.05)),
                (int(w * 0.15), int(h * 0.95)),
                (w, h),
                (w, 0)
            ]
            target_size = (w, h)
        elif camera == "down_soft":
            pa = [
                (int(w * 0.05), 0),
                (0, h),
                (w, h),
                (int(w * 0.95), 0)
            ]
            target_size = (w, h)
        elif camera == "left_steep":
            pa = [
                (0, 0),
                (0, h),
                (int(w * 0.70), int(h * 0.88)),
                (int(w * 0.70), int(h * 0.12))
            ]
            target_size = (int(w * 0.70), h)
        elif camera == "right_steep":
            pa = [
                (int(w * 0.30), int(h * 0.12)),
                (int(w * 0.30), int(h * 0.88)),
                (w, h),
                (w, 0)
            ]
            target_size = (w, h)
        else: # frontal
            pa = None
            target_size = (w, h)
            
        if pa:
            pb = [(0, 0), (0, h), (w, h), (w, 0)]
            coeffs = find_coeffs(pa, pb)
            pil_img = pil_img.transform(target_size, Image.Transform.PERSPECTIVE, coeffs, Image.Resampling.BICUBIC)
            w, h = pil_img.size
            
        # 3. Canvas setup
        canvas_width = 1024
        canvas_height = 1536
        canvas = Image.new("RGB", (canvas_width, canvas_height), color=(240, 240, 240))
        
        # Calculate target size based on physical scale logic (max dimension of artwork)
        long_side_cm = max(args.width_cm, args.height_cm)
        if long_side_cm <= 45:
            fill_ratio = 0.18
        elif long_side_cm <= 80:
            fill_ratio = 0.25
        elif long_side_cm <= 120:
            fill_ratio = 0.32
        elif long_side_cm <= 160:
            fill_ratio = 0.38
        elif long_side_cm <= 220:
            fill_ratio = 0.48
        else:
            fill_ratio = 0.58
            
        max_art_dim = int(canvas_width * fill_ratio)
        ratio = min(max_art_dim / w, max_art_dim / h)
        new_w = (int(w * ratio) // 8) * 8
        new_h = (int(h * ratio) // 8) * 8
        
        art_resized = pil_img.resize((new_w, new_h), Image.Resampling.LANCZOS)
        
        # Position slightly above vertical center for classic gallery layout
        x = (canvas_width - new_w) // 2
        y = int((canvas_height - new_h) * 0.35)
        
        canvas.paste(art_resized, (x, y), art_resized if art_resized.mode == "RGBA" else None)
        canvas.save(base_path, format="PNG")
        
        # 4. Mask creation (black = protect, white = generate)
        mask_img = Image.new("L", (canvas_width, canvas_height), color=255)
        art_mask_part = Image.new("L", art_resized.size, color=0)
        
        if art_resized.mode == "RGBA":
            alpha = art_resized.split()[3]
            mask_img.paste(art_mask_part, (x, y), mask=alpha)
        else:
            mask_img.paste(art_mask_part, (x, y))
            
        mask_img.save(mask_path, format="PNG")
        
        # 5. Define scene prompt
        scene_prompts = {
            "palazzo": "A luxurious mockup room inside a classic European palazzo, high ceilings, ornate wall paneling, subtle golden gilding, elegant herringbone parquet floor. Classic architectural details. Natural soft light coming from a large window on the side. Museum hanging style.",
            "atelier": "A warm, cozy artist atelier, cluttered with brushes, canvases leaning against walls, soft ambient light, warm wooden textures, bohemian atmosphere, a brick wall. A discreet standing adult scale figure looking at the art piece.",
            "catalan": "A premium modernist apartment interior in Barcelona, Catalan modernist style architectural details, hydraulic tile floor, high ceilings with decorative plaster moldings, warm Mediterranean evening light. Museum hanging style.",
            "contemporary": "A modern premium minimalist collector loft, concrete walls, large windows, sleek dark wood flooring, contemporary gallery design, clean lines. Soft ambient gallery track lighting."
        }
        
        scene_text = scene_prompts.get(args.scene, scene_prompts["contemporary"])
        prompt = f"{scene_text}\n\n- Artwork Dimensions: {args.width_cm} cm wide x {args.height_cm} cm high\n- mockup"
        
        # Adjust prompt based on camera
        camera_directives = {
            "left_soft": "three-quarter left perspective view",
            "right_soft": "three-quarter right perspective view",
            "down_soft": "low-angle front view",
            "left_steep": "three-quarter left perspective view",
            "right_steep": "three-quarter right perspective view",
            "frontal": "frontal eye-level view"
        }
        prompt += f"\n- Camera View: {camera_directives.get(camera, 'frontal eye-level view')}"
        prompt += (
            "\n\nHARMONIZATION AND INTEGRATION DIRECTIVES:\n"
            "- Keep the artwork surface itself unchanged. Do not repaint, reinterpret, alter, crop, mirror, rotate, recolor, simplify, extend, or replace the artwork.\n"
            "- The newly generated background room/gallery must harmonize beautifully with the artwork's color palette, tone, style, and mood.\n"
            "- The artwork must hang at a natural gallery eye-level height on the wall, leaving more floor space visible below it than ceiling space above (do not center it vertically in the room).\n"
            "- Render realistic lighting on the wall and the artwork boundaries, matching the natural light sources in the room.\n"
            "- Add subtle, soft contact shadows and realistic depth at the edges where the artwork meets the wall.\n"
            "- The artwork must look perfectly integrated, like a real physical painting hung in a premium space, not pasted or floating."
        )
        
        metadata["prompt"] = prompt
        
        # 6. Execute Vertex AI Inpainting Call
        client = get_client()
        
        # Prepare image bytes
        img_bytes_io = io.BytesIO()
        canvas.save(img_bytes_io, format='PNG')
        base_bytes = img_bytes_io.getvalue()
        
        mask_bytes_io = io.BytesIO()
        mask_img.save(mask_bytes_io, format='PNG')
        mask_bytes = mask_bytes_io.getvalue()
        
        raw_ref = types.RawReferenceImage(
            reference_id=1,
            reference_image=types.Image(image_bytes=base_bytes, mime_type="image/png")
        )
        
        mask_ref = types.MaskReferenceImage(
            reference_id=2,
            reference_image=types.Image(image_bytes=mask_bytes, mime_type="image/png"),
            config=types.MaskReferenceConfig(
                mask_mode="MASK_MODE_USER_PROVIDED",
                mask_dilation=0.015
            )
        )
        
        print(f"Calling Vertex AI Imagen 3 edit_image for {prefix}...", flush=True)
        start_time = time.time()
        
        response = call_with_retry(
            lambda: client.models.edit_image(
                model=args.model,
                prompt=prompt,
                reference_images=[raw_ref, mask_ref],
                config=types.EditImageConfig(
                    number_of_images=1,
                    output_mime_type="image/png",
                    edit_mode="EDIT_MODE_INPAINT_INSERTION"
                )
            )
        )
        
        duration = time.time() - start_time
        metadata["duration_seconds"] = round(duration, 2)
        
        if not response.generated_images:
            raise RuntimeError("No image returned from Imagen edit_image API.")
            
        result_bytes = response.generated_images[0].image.image_bytes
        with open(result_path, "wb") as f:
            f.write(result_bytes)
            
        metadata["status"] = "success"
        print(f"SUCCESS: Saved results to {result_path} in {duration:.2f}s", flush=True)
        
    except Exception as e:
        print(f"ERROR executing run {prefix}: {e}", file=sys.stderr)
        metadata["status"] = "error"
        metadata["error"] = str(e)
        
    # Write metadata JSON
    with open(meta_path, "w", encoding="utf-8") as f:
        json.dump(metadata, f, indent=4, ensure_ascii=False)

if __name__ == "__main__":
    main()
