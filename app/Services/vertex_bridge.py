import os
import sys
import argparse
import time
import random
from google import genai
from google.genai import types
from google.genai.errors import ClientError
from PIL import Image

def get_client():
    # Initialize the client with Vertex AI, location global, and the specific project
    return genai.Client(
        vertexai=True,
        project="project-3c7fb926-f021-47c6-9cc",
        location="global"
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
                
            if is_rate_limit and attempt < max_retries - 1:
                # Exponential backoff: 5s, 10s, 20s, 40s... plus jitter
                sleep_time = (2 ** attempt) * 5 + random.uniform(1, 5)
                print(f"Rate limited (429). Retrying in {sleep_time:.2f} seconds (attempt {attempt + 1}/{max_retries})...", file=sys.stderr)
                time.sleep(sleep_time)
                last_error = e
                continue
            raise e
        except Exception as e:
            raise e
    if last_error:
        raise last_error

def handle_generate_text(args):
    client = get_client()
    contents = []
    
    if args.prompt:
        contents.append(args.prompt)
        
    if args.image:
        for img_path in args.image:
            if not os.path.isfile(img_path):
                raise FileNotFoundError(f"Image not found at: {img_path}")
            contents.append(Image.open(img_path))
        
    if not contents:
        raise ValueError("Must provide either --prompt or --image.")
        
    response = call_with_retry(
        lambda: client.models.generate_content(
            model=args.model,
            contents=contents
        )
    )
    
    # Output only the generated text to stdout
    print(response.text)

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

def handle_generate_image(args):
    client = get_client()
    
    if not args.output:
        raise ValueError("Must provide --output path for image generation.")
        
    # Check if the model is a Gemini multimodal image generation model
    model_name = args.model if args.model else ""
    model_lower = model_name.lower()
    is_gemini_image = "gemini" in model_lower and "image" in model_lower
    
    if is_gemini_image:
        # Map friendly name to the correct Vertex AI model path
        if "gemini-3-pro-image" in model_lower:
            resolved_model = "publishers/google/models/gemini-3-pro-image"
        else:
            resolved_model = "publishers/google/models/gemini-3.1-flash-image" # Default/fallback to 3.1 flash
            
        contents = [args.prompt]
        if args.image:
            for img_path in args.image:
                if not os.path.isfile(img_path):
                    raise FileNotFoundError(f"Image not found at: {img_path}")
                img = Image.open(img_path)
                # Downscale to max 1024 for Gemini multimodal image generation models
                max_dim = 1024
                w, h = img.size
                if w > max_dim or h > max_dim:
                    ratio = min(max_dim / w, max_dim / h)
                    img = img.resize((int(w * ratio), int(h * ratio)), Image.Resampling.LANCZOS)
                # Convert RGBA/other modes to RGB to avoid API errors
                if img.mode != "RGB":
                    img = img.convert("RGB")
                contents.append(img)
                
        response = call_with_retry(
            lambda: client.models.generate_content(
                model=resolved_model,
                contents=contents
            )
        )
        
        if not response.candidates:
            raise RuntimeError("No candidates in Gemini response.")
            
        img_found = False
        for part in response.candidates[0].content.parts:
            if part.inline_data:
                img = part.as_image()
                # Create parent directories if they don't exist
                out_dir = os.path.dirname(args.output)
                if out_dir and not os.path.exists(out_dir):
                    os.makedirs(out_dir, exist_ok=True)
                img.save(args.output)
                print(f"SUCCESS: Image saved to {args.output}")
                img_found = True
                break
                
        if not img_found:
            raise RuntimeError("No image was returned in the Gemini response parts.")
            
        return
        
    if args.image:
        # Image-to-image or background replacement using edit_image
        base_image_path = args.image[0]
        if not os.path.isfile(base_image_path):
            raise FileNotFoundError(f"Base image not found at: {base_image_path}")
            
        # Open and align image dimensions to prevent 1-pixel rounding errors in Vertex AI backend
        pil_img = Image.open(base_image_path).convert("RGBA")
        w, h = pil_img.size
        
        # Check if this is a mockup generation request (Form 2)
        is_mockup = "mockup" in args.prompt.lower()
        mask_bytes = None
        
        if is_mockup:
            # Check camera perspective direction
            prompt_lower = args.prompt.lower()
            warp_dir = None
            if "three_quarter_left" in prompt_lower or "three-quarter-left" in prompt_lower or "3/4 left" in prompt_lower:
                warp_dir = "left"
            elif "three_quarter_right" in prompt_lower or "three-quarter-right" in prompt_lower or "3/4 right" in prompt_lower:
                warp_dir = "right"
                
            if warp_dir:
                # Apply 3/4 perspective skew
                pb = [(0, 0), (0, h), (w, h), (w, 0)]
                if warp_dir == "left":
                    # Left side is closer (larger), right side is further (smaller)
                    # Compress horizontal width to 70% for a steeper 3/4 perspective to resolve visual stretching
                    pa = [
                        (0, 0),
                        (0, h),
                        (int(w * 0.70), int(h * 0.85)),
                        (int(w * 0.70), int(h * 0.15))
                    ]
                    target_size = (int(w * 0.70), h)
                else:
                    # Left side is further (smaller), right side is closer (larger)
                    # Compress horizontal width to 70% (offset 0.30) for a steeper 3/4 perspective to resolve visual stretching
                    pa = [
                        (int(w * 0.30), int(h * 0.15)),
                        (int(w * 0.30), int(h * 0.85)),
                        (w, h),
                        (w, 0)
                    ]
                    target_size = (w, h)
                
                coeffs = find_coeffs(pa, pb)
                pil_img = pil_img.transform(target_size, Image.Transform.PERSPECTIVE, coeffs, Image.Resampling.BICUBIC)
                w, h = pil_img.size
            
            # Create a composite canvas (neutral gray wall) representing the room
            canvas_size = 1024
            canvas = Image.new("RGB", (canvas_size, canvas_size), color=(240, 240, 240))
            
            # Calculate target size for the artwork on the wall based on real size
            import re
            match = re.search(r"(\d+(?:\.\d+)?)\s*cm\s+wide\s*x\s*(\d+(?:\.\d+)?)\s*cm\s+high", args.prompt)
            
            fill_ratio = 0.35
            if match:
                try:
                    width_cm = float(match.group(1))
                    height_cm = float(match.group(2))
                    long_side_cm = max(width_cm, height_cm)
                    
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
                except Exception:
                    pass
            
            # Apply 50% reduction (multiplier 0.50) if a human scale figure is present to resolve the remaining 20-25% size gap
            prompt_lower = args.prompt.lower()
            has_human = any(kw in prompt_lower for kw in ["discreet standing", "standing adult", "standing human", "scale figure"])
            if has_human:
                fill_ratio *= 0.50
            
            max_art_dim = int(canvas_size * fill_ratio)
            
            ratio = min(max_art_dim / w, max_art_dim / h)
            new_w = int(w * ratio)
            new_h = int(h * ratio)
            
            # Enforce multiples of 8 for dimensions
            new_w = (new_w // 8) * 8
            new_h = (new_h // 8) * 8
            
            art_resized = pil_img.resize((new_w, new_h), Image.Resampling.LANCZOS)
            
            # Position: center horizontally, slightly above center vertically (classic gallery hang)
            x = (canvas_size - new_w) // 2
            y = int((canvas_size - new_h) * 0.35)
            
            # Use alpha mask for pasting if image has alpha
            canvas.paste(art_resized, (x, y), art_resized if art_resized.mode == "RGBA" else None)
            
            # Use the composite canvas as our reference image
            pil_img = canvas
            
            # Create user-provided mask image:
            # Grayscale ("L") mode
            # 255 = White (the area to be edited/replaced by the model)
            # 0 = Black (the area to keep/preserve)
            import io
            mask_img = Image.new("L", (canvas_size, canvas_size), color=255)
            art_mask_part = Image.new("L", art_resized.size, color=0)
            if art_resized.mode == "RGBA":
                alpha = art_resized.split()[3]
                mask_img.paste(art_mask_part, (x, y), mask=alpha)
            else:
                mask_img.paste(art_mask_part, (x, y))
                
            mask_byte_arr = io.BytesIO()
            mask_img.save(mask_byte_arr, format='PNG')
            mask_bytes = mask_byte_arr.getvalue()
            
            # Append critical harmonization directives to the prompt
            args.prompt += (
                "\n\nHARMONIZATION AND INTEGRATION DIRECTIVES:\n"
                "- The newly generated background room/gallery must harmonize beautifully with the artwork's color palette, tone, style, and mood.\n"
                "- Render realistic lighting on the wall and the artwork boundaries, matching the natural light sources in the room.\n"
                "- Add subtle, soft contact shadows and realistic depth at the edges where the artwork meets the wall.\n"
                "- The artwork must look perfectly integrated, like a real physical painting hung in a premium space, not pasted or floating."
            )
        else:
            # Form 1: root artwork enhancement (keep full frame, just align to multiples of 8)
            new_w = (w // 8) * 8
            new_h = (h // 8) * 8
            
            # Downscale if image is too large to save bandwidth and improve latency
            max_dim = 1536
            if new_w > max_dim or new_h > max_dim:
                ratio = min(max_dim / new_w, max_dim / new_h)
                new_w = int(new_w * ratio)
                new_h = int(new_h * ratio)
                # Re-enforce multiples of 8
                new_w = (new_w // 8) * 8
                new_h = (new_h // 8) * 8
                
            if (new_w, new_h) != (w, h):
                pil_img = pil_img.resize((new_w, new_h), Image.Resampling.LANCZOS)
            
        import io
        img_byte_arr = io.BytesIO()
        pil_img.save(img_byte_arr, format='PNG')
        image_bytes = img_byte_arr.getvalue()
        mime_type = "image/png"
            
        raw_ref = types.RawReferenceImage(
            reference_id=1,
            reference_image=types.Image(
                image_bytes=image_bytes,
                mime_type=mime_type
            )
        )
        
        if is_mockup and mask_bytes is not None:
            mask_ref = types.MaskReferenceImage(
                reference_id=2,
                reference_image=types.Image(
                    image_bytes=mask_bytes,
                    mime_type="image/png"
                ),
                config=types.MaskReferenceConfig(
                    mask_mode="MASK_MODE_USER_PROVIDED",
                    mask_dilation=0.015
                )
            )
            edit_mode = "EDIT_MODE_INPAINT_INSERTION"
        else:
            mask_ref = types.MaskReferenceImage(
                reference_id=2,
                config=types.MaskReferenceConfig(
                    mask_mode="MASK_MODE_BACKGROUND"
                )
            )
            edit_mode = None
            
        # Use capability model by default for editing
        model = args.model if args.model else "imagen-3.0-capability-001"
        
        config_args = {
            "number_of_images": 1,
            "output_mime_type": "image/png"
        }
        if edit_mode:
            config_args["edit_mode"] = edit_mode
            
        response = call_with_retry(
            lambda: client.models.edit_image(
                model=model,
                prompt=args.prompt,
                reference_images=[raw_ref, mask_ref],
                config=types.EditImageConfig(**config_args)
            )
        )
    else:
        # Text-to-image generation
        model = args.model if args.model else "imagen-3.0-generate-002"
        
        response = call_with_retry(
            lambda: client.models.generate_images(
                model=model,
                prompt=args.prompt,
                config=types.GenerateImagesConfig(
                    number_of_images=1,
                    aspect_ratio=args.aspect_ratio,
                    output_mime_type="image/png"
                )
            )
        )
        
    if not response.generated_images:
        raise RuntimeError("No image was generated by the model.")
        
    # Save the first generated image to the output path
    image_bytes = response.generated_images[0].image.image_bytes
    
    # Create parent directories if they don't exist
    out_dir = os.path.dirname(args.output)
    if out_dir and not os.path.exists(out_dir):
        os.makedirs(out_dir, exist_ok=True)
        
    with open(args.output, "wb") as f:
        f.write(image_bytes)
        
    print(f"SUCCESS: Image saved to {args.output}")

def main():
    parser = argparse.ArgumentParser(description="Vertex AI CLI Bridge")
    subparsers = parser.add_subparsers(dest="command", required=True)
    
    # Subcommand: generate-text
    text_parser = subparsers.add_parser("generate-text", help="Generate text / analysis using Gemini")
    text_parser.add_argument("--prompt", type=str, help="Text prompt")
    text_parser.add_argument("--image", type=str, action="append", help="Path to the input image(s)")
    text_parser.add_argument("--model", type=str, default="gemini-2.5-flash", help="Gemini model name")
    
    # Subcommand: generate-image
    image_parser = subparsers.add_parser("generate-image", help="Generate/Edit images using Imagen or Gemini")
    image_parser.add_argument("--prompt", type=str, required=True, help="Text prompt")
    image_parser.add_argument("--image", type=str, action="append", help="Path to reference base image(s) (for editing/multimodal)")
    image_parser.add_argument("--model", type=str, help="Imagen or Gemini model name")
    image_parser.add_argument("--aspect_ratio", type=str, default="1:1", help="Aspect ratio (e.g. 1:1, 4:3, 16:9)")
    image_parser.add_argument("--output", type=str, required=True, help="Output file path where image will be saved")
    
    args = parser.parse_args()
    
    try:
        if hasattr(args, 'prompt') and args.prompt == "-":
            # Read prompt from stdin
            args.prompt = sys.stdin.read()
            
        if args.command == "generate-text":
            handle_generate_text(args)
        elif args.command == "generate-image":
            handle_generate_image(args)
    except Exception as e:
        import traceback
        traceback.print_exc(file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
