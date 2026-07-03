import os
import sys

# Set fallback Google Application Default Credentials when running under Apache / SYSTEM
if 'GOOGLE_APPLICATION_CREDENTIALS' not in os.environ:
    fallback_adc = r"C:\laragon\www\mockups\storage\credentials.json"
    if os.path.exists(fallback_adc):
        os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = fallback_adc

import argparse
import time
import random
from google import genai
from google.genai import types
from google.genai.errors import ClientError
from google.auth.exceptions import TransportError
from PIL import Image

MOCKUP_USE_PRECOMPOSITION = os.environ.get("MOCKUP_USE_PRECOMPOSITION", "false").lower() == "true"
MOCKUP_USE_BACKGROUND_EDIT = os.environ.get("MOCKUP_USE_BACKGROUND_EDIT", "false").lower() == "true"
# Plate-driven nadir precomposition for Camera 15. Each artwork orientation selects a
# dedicated silhouette plate so inpainting receives camera geometry before room synthesis.
MOCKUP_USE_NADIR_POLYGON = os.environ.get("MOCKUP_USE_NADIR_POLYGON", "false").lower() == "true"
MOCKUP_NADIR_PLATE_DIR = os.environ.get("MOCKUP_NADIR_PLATE_DIR", "")
MOCKUP_GRAPHIC_PERSPECTIVE_PLATE = os.environ.get("MOCKUP_GRAPHIC_PERSPECTIVE_PLATE", "")
MOCKUP_MASK_DILATION = os.environ.get("MOCKUP_MASK_DILATION", "")
# Center-crop zoom factor applied to the world mother style reference before sending it to the
# model. 1.0 = unchanged; >1.0 crops tighter toward the center (more material/texture, less room
# layout). Values <= 1.0 are treated as a no-op (cropping cannot show more than the source photo).
MOCKUP_WORLD_MOTHER_SCALE = os.environ.get("MOCKUP_WORLD_MOTHER_SCALE", "")
MOCKUP_PROMPT_FIRST_MODE = os.environ.get("MOCKUP_PROMPT_FIRST_MODE", "false").lower() == "true"
MOCKUP_PROMPT_FIRST_NO_MASK_MODE = os.environ.get("MOCKUP_PROMPT_FIRST_NO_MASK_MODE", "false").lower() == "true"
if MOCKUP_PROMPT_FIRST_MODE:
    MOCKUP_USE_PRECOMPOSITION = False
    if MOCKUP_PROMPT_FIRST_NO_MASK_MODE:
        MOCKUP_USE_BACKGROUND_EDIT = False

def get_client():
    import httpx
    # Punto #4: project ID desde variable de entorno, fallback al valor original
    project_id = os.environ.get('VERTEX_PROJECT_ID', 'project-3c7fb926-f021-47c6-9cc')
    # Initialize the client with Vertex AI, location global, and the specific project
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
                    # Para error 429, usar backoff más corto: 3s, 6s, 12s, 24s máximo
                    sleep_time = min(24, (2 ** attempt) * 3) + random.uniform(0.5, 2)
                    print(f"Rate limited (429). Retrying in {sleep_time:.2f} seconds (attempt {attempt + 1}/{max_retries})...", file=sys.stderr)
                    time.sleep(sleep_time)
                    last_error = e
                    continue
                else:
                    # En último intento, fallar inmediatamente sin reintentar
                    print(f"Rate limit exhausted after {max_retries} attempts. Failing.", file=sys.stderr)
                    raise e
            else:
                raise e
        except TransportError as e:
            if attempt < max_retries - 1:
                sleep_time = (2 ** attempt) * 5 + random.uniform(1, 5)
                print(f"Google auth transport error. Retrying in {sleep_time:.2f} seconds (attempt {attempt + 1}/{max_retries})...", file=sys.stderr)
                time.sleep(sleep_time)
                last_error = e
                continue
            raise e
        except Exception as e:
            message = str(e).lower()
            is_transient_network = (
                "winerror 10013" in message
                or "failed to establish a new connection" in message
                or "max retries exceeded" in message
                or "transporterror" in message
            )
            if is_transient_network and attempt < max_retries - 1:
                sleep_time = (2 ** attempt) * 5 + random.uniform(1, 5)
                print(f"Transient network error. Retrying in {sleep_time:.2f} seconds (attempt {attempt + 1}/{max_retries})...", file=sys.stderr)
                time.sleep(sleep_time)
                last_error = e
                continue
            raise e
    if last_error:
        raise last_error

def is_rate_limit_error(error):
    if isinstance(error, ClientError):
        if hasattr(error, 'status_code') and error.status_code == 429:
            return True
        return "429" in str(error) or "exhausted" in str(error).lower()
    return "429" in str(error) or "exhausted" in str(error).lower()

def image_model_fallback_chain(selected_model, model_map):
    fallback_order = [
        "gemini-3.1-flash-image",
        "gemini-2.5-flash-image",
    ]
    chain = [selected_model] if selected_model in model_map else ["gemini-3.1-flash-image"]

    for model in fallback_order:
        if model not in chain:
            chain.append(model)

    return chain

def prompt_float_control(prompt, key, default):
    import re
    match = re.search(rf"^\s*{re.escape(key)}\s*=\s*([0-9]+(?:\.[0-9]+)?)\s*$", prompt, re.IGNORECASE | re.MULTILINE)
    if not match:
        return default
    try:
        return float(match.group(1))
    except Exception:
        return default

def normalize_camera_text(prompt_text: str) -> str:
    if not prompt_text:
        return ""
    return prompt_text.lower().strip()

def detect_perspective_side(prompt_text: str) -> str | None:
    normalized = normalize_camera_text(prompt_text)
    
    left_patterns = [
        "three_quarter_left",
        "three-quarter-left",
        "three-quarter left",
        "three quarter left",
        "left three-quarter",
        "left three quarter",
        "left oblique",
        "3/4 left",
        "seven-eighths left",
        "seven eighths left",
        "7/8 left",
        "low-angle seven-eighths left",
        "low angle seven eighths left",
        "subtle low-angle seven-eighths left view"
    ]
    
    right_patterns = [
        "three_quarter_right",
        "three-quarter-right",
        "three-quarter right",
        "three quarter right",
        "right three-quarter",
        "right three quarter",
        "right oblique",
        "3/4 right",
        "seven-eighths right",
        "seven eighths right",
        "7/8 right",
        "low-angle seven-eighths right",
        "low angle seven eighths right",
        "subtle low-angle seven-eighths right view"
    ]
    
    if any(pattern in normalized for pattern in left_patterns):
        return "left"
    if any(pattern in normalized for pattern in right_patterns):
        return "right"
    return None

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

def detect_artwork_orientation(w, h):
    if h > w * 1.12:
        return "portrait"
    if w > h * 1.12:
        return "landscape"
    return "square"

def nadir_plate_path_for_orientation(orientation):
    plate_dir = MOCKUP_NADIR_PLATE_DIR
    if not plate_dir:
        project_root = os.path.dirname(os.path.dirname(os.path.dirname(__file__)))
        plate_dir = os.path.join(project_root, "results", "resized")

    filenames = {
        "portrait": "impainting-obra-vertical.jpg",
        "square": "inmpainting-obra-cuadrada-1.jpg",
        "landscape": "inmpainting-obra-horizontal.jpg",
    }
    return os.path.join(plate_dir, filenames[orientation])

def green_silhouette_mask(plate_img):
    rgb = plate_img.convert("RGB")
    mask = Image.new("L", rgb.size, 0)
    src = rgb.load()
    dst = mask.load()
    width, height = rgb.size

    # JPG compression creates near-green fringes, so keep a tolerant chroma test.
    for y in range(height):
        for x in range(width):
            r, g, b = src[x, y]
            if g > 105 and g > r * 1.8 and g > b * 1.8:
                dst[x, y] = 255

    return mask

def quad_from_mask(mask_img):
    bbox = mask_img.getbbox()
    if not bbox:
        raise ValueError("Nadir silhouette plate has no detectable green artwork mask.")

    pix = mask_img.load()
    left, top, right, bottom = bbox
    points = []
    for y in range(top, bottom):
        for x in range(left, right):
            if pix[x, y] > 0:
                points.append((x, y))

    if not points:
        raise ValueError("Nadir silhouette plate has no usable mask pixels.")

    tl = min(points, key=lambda p: p[0] + p[1])
    tr = min(points, key=lambda p: -p[0] + p[1])
    bl = max(points, key=lambda p: -p[0] + p[1])
    br = max(points, key=lambda p: p[0] + p[1])
    return [tl, bl, br, tr]

def horizontal_span_at(mask_img, y, left, right):
    pix = mask_img.load()
    xs = [x for x in range(left, right) if pix[x, y] > 0]
    if not xs:
        return None
    return min(xs), max(xs)

def span_near_fraction(mask_img, bbox, fraction, search_radius=42):
    left, top, right, bottom = bbox
    target_y = int(round(top + (bottom - top) * fraction))
    start_y = max(top, target_y - search_radius)
    end_y = min(bottom - 1, target_y + search_radius)

    best = None
    for y in range(start_y, end_y + 1):
        span = horizontal_span_at(mask_img, y, left, right)
        if not span:
            continue
        x1, x2 = span
        width = x2 - x1
        score = abs(y - target_y) * 3 - width
        if best is None or score < best[0]:
            best = (score, y, x1, x2)

    if best is None:
        return None
    _, y, x1, x2 = best
    return y, x1, x2

def nadir_rigid_canvas_quad(orientation, canvas_w, canvas_h, silhouette=None):
    """Camera 15 needs a strong low-angle canvas plane, not a vanishing spike.

    The plate selects the camera family and canvas aspect, but the protected artwork
    follows the perspective marked by the green plate.
    """
    if silhouette is not None:
        bbox = silhouette.getbbox()
        if bbox:
            left, top, right, bottom = bbox
            top_fraction = 0.12 if orientation == "portrait" else 0.16
            bottom_fraction = 0.88 if orientation == "portrait" else 0.82
            top_span = span_near_fraction(silhouette, bbox, top_fraction)
            bottom_span = span_near_fraction(silhouette, bbox, bottom_fraction)

            if top_span and bottom_span:
                top_y, top_left, top_right = top_span
                bottom_y, bottom_left, bottom_right = bottom_span

                pad = max(8, int(round(canvas_w * 0.012)))
                top_left = max(pad, min(canvas_w - pad, top_left))
                top_right = max(pad, min(canvas_w - pad, top_right))
                bottom_left = max(pad, min(canvas_w - pad, bottom_left))
                bottom_right = max(pad, min(canvas_w - pad, bottom_right))

                if top_left >= top_right:
                    top_left, top_right = top_right - pad, top_left + pad
                if bottom_left >= bottom_right:
                    bottom_left, bottom_right = bottom_right - pad, bottom_left + pad

                return [
                    (top_left, top_y),
                    (bottom_left, bottom_y),
                    (bottom_right, bottom_y),
                    (top_right, top_y),
                ]

    fallback_profiles = {
        "portrait": [(0.38, 0.24), (0.23, 0.80), (0.82, 0.72), (0.66, 0.12)],
        "square": [(0.16, 0.38), (0.09, 0.72), (0.88, 0.64), (0.62, 0.28)],
        "landscape": [(0.22, 0.28), (0.16, 0.58), (0.84, 0.59), (0.68, 0.42)],
    }
    return [(int(round(x * canvas_w)), int(round(y * canvas_h))) for x, y in fallback_profiles.get(orientation, fallback_profiles["square"])]

def build_nadir_polygon_precomposition(art_img, w, h):
    """Warp artwork into an orientation-specific Camera 15 silhouette plate.

    The plate selects the orientation/camera canvas, while a moderated rigid-canvas
    quad becomes the protected artwork surface. The rest is editable context for
    Imagen/Gemini inpainting. Returns
    (composited_rgb_canvas, mask_png_bytes, orientation, plate_path).
    """
    import io

    orientation = detect_artwork_orientation(w, h)
    plate_path = nadir_plate_path_for_orientation(orientation)
    if not os.path.isfile(plate_path):
        raise FileNotFoundError(f"Nadir silhouette plate not found for {orientation}: {plate_path}")

    plate_img = Image.open(plate_path).convert("RGB")
    canvas_w, canvas_h = plate_img.size
    silhouette = green_silhouette_mask(plate_img)
    pa = nadir_rigid_canvas_quad(orientation, canvas_w, canvas_h, silhouette)
    pb = [(0, 0), (0, h), (w, h), (w, 0)]
    coeffs = find_coeffs(pa, pb)

    art_transformed = art_img.convert("RGBA").transform(
        (canvas_w, canvas_h), Image.Transform.PERSPECTIVE, coeffs,
        Image.Resampling.BICUBIC, fillcolor=(0, 0, 0, 0)
    ).convert("RGBA")
    alpha = art_transformed.split()[3]

    canvas = Image.new("RGBA", (canvas_w, canvas_h), color=(240, 240, 240, 255))
    canvas.paste(art_transformed, (0, 0), art_transformed)
    canvas = canvas.convert("RGB")

    # 255 = editable context, 0 = protected artwork silhouette.
    mask_img = Image.new("L", (canvas_w, canvas_h), color=255)
    protected_layer = Image.new("L", (canvas_w, canvas_h), color=0)
    mask_img.paste(protected_layer, (0, 0), mask=alpha)

    mask_byte_arr = io.BytesIO()
    mask_img.save(mask_byte_arr, format='PNG')
    return canvas, mask_byte_arr.getvalue(), orientation, plate_path

def build_graphic_perspective_precomposition(art_img, plate_path):
    """Use a green silhouette plate as literal geometry for protected inpainting."""
    import io

    if not os.path.isfile(plate_path):
        raise FileNotFoundError(f"Graphic perspective plate not found: {plate_path}")

    plate_img = Image.open(plate_path).convert("RGB")
    canvas_w, canvas_h = plate_img.size
    silhouette = green_silhouette_mask(plate_img)
    pa = quad_from_mask(silhouette)

    src = art_img.convert("RGBA")
    w, h = src.size
    pb = [(0, 0), (0, h), (w, h), (w, 0)]
    coeffs = find_coeffs(pa, pb)

    art_transformed = src.transform(
        (canvas_w, canvas_h), Image.Transform.PERSPECTIVE, coeffs,
        Image.Resampling.BICUBIC, fillcolor=(0, 0, 0, 0)
    ).convert("RGBA")

    original_alpha = art_transformed.split()[3]
    exact_alpha = Image.new("L", (canvas_w, canvas_h), 0)
    exact_alpha.paste(original_alpha, (0, 0), mask=silhouette)
    art_transformed.putalpha(exact_alpha)

    canvas = Image.new("RGBA", (canvas_w, canvas_h), color=(240, 240, 240, 255))
    canvas.paste(art_transformed, (0, 0), art_transformed)
    canvas = canvas.convert("RGB")

    mask_img = Image.new("L", (canvas_w, canvas_h), color=255)
    protected_layer = Image.new("L", (canvas_w, canvas_h), color=0)
    mask_img.paste(protected_layer, (0, 0), mask=exact_alpha)

    mask_byte_arr = io.BytesIO()
    mask_img.save(mask_byte_arr, format="PNG")
    return canvas, mask_byte_arr.getvalue(), plate_path

def handle_generate_image(args):
    client = get_client()
    
    if not args.output:
        raise ValueError("Must provide --output path for image generation.")
        
    is_mockup = "mockup" in args.prompt.lower()

    # Check if the model is a Gemini multimodal image generation model
    model_name = args.model if args.model else ""
    model_lower = model_name.lower()
    is_gemini_image = "gemini" in model_lower and "image" in model_lower
    
    pil_img = None
    gemini_reference_images = []
    mask_bytes = None
    # Second --image argument (when present) is the world mother / environment reference.
    # Consumed by masked inpainting as a StyleReferenceImage.
    style_reference_path = args.image[1] if args.image and len(args.image) > 1 and os.path.isfile(args.image[1]) else None

    if args.image:
        if is_gemini_image and len(args.image) > 1:
            for image_path in args.image:
                if not os.path.isfile(image_path):
                    raise FileNotFoundError(f"Reference image not found at: {image_path}")
                ref_img = Image.open(image_path).convert("RGB")
                w, h = ref_img.size
                max_dim = 1024
                if w > max_dim or h > max_dim:
                    ratio = min(max_dim / w, max_dim / h)
                    new_w = max(8, int(w * ratio) // 8 * 8)
                    new_h = max(8, int(h * ratio) // 8 * 8)
                    ref_img = ref_img.resize((new_w, new_h), Image.Resampling.LANCZOS)
                gemini_reference_images.append(ref_img)
            pil_img = gemini_reference_images[0]
        else:
            base_image_path = args.image[0]
            if not os.path.isfile(base_image_path):
                raise FileNotFoundError(f"Base image not found at: {base_image_path}")
                
            # Open and align image dimensions to prevent 1-pixel rounding errors in Vertex AI backend
            pil_img = Image.open(base_image_path).convert("RGBA")
            w, h = pil_img.size
        
        w, h = pil_img.size

        use_nadir_polygon = (
            MOCKUP_USE_NADIR_POLYGON
            and not gemini_reference_images
            and is_mockup
            and MOCKUP_USE_PRECOMPOSITION
        )
        use_graphic_perspective_plate = (
            MOCKUP_GRAPHIC_PERSPECTIVE_PLATE
            and not gemini_reference_images
            and is_mockup
            and MOCKUP_USE_PRECOMPOSITION
        )

        if use_graphic_perspective_plate:
            pil_img, mask_bytes, plate_path = build_graphic_perspective_precomposition(
                pil_img,
                MOCKUP_GRAPHIC_PERSPECTIVE_PLATE
            )
            print(
                f"[DEBUG] Using graphic perspective plate precomposition: plate={os.path.basename(plate_path)}.",
                file=sys.stderr
            )
            args.prompt += (
                "\n\nTECHNICAL INPAINTING DIRECTIVES:\n"
                "- The input image already contains the artwork pre-warped into the exact protected perspective shape.\n"
                "- Keep the protected artwork unchanged.\n"
                "- Generate only the surrounding room, wall, floor, shadows, and lighting so they support that protected perspective."
            )
        elif use_nadir_polygon:
            pil_img, mask_bytes, plate_orientation, plate_path = build_nadir_polygon_precomposition(pil_img, w, h)
            print(
                f"[DEBUG] Using Camera 15 nadir plate precomposition: orientation={plate_orientation}, plate={os.path.basename(plate_path)}.",
                file=sys.stderr
            )
            args.prompt += (
                "\n\nHARMONIZATION AND INTEGRATION DIRECTIVES:\n"
                "- The input image already shows the artwork pre-warped into an orientation-specific rigid canvas plane for an extreme low-angle nadir/contrapicado camera, positioned off-axis near a floor corner. This is intentional camera geometry, not a rendering error.\n"
                "- Keep the artwork surface itself unchanged: do not repaint, reinterpret, alter, crop, mirror, rotate, recolor, simplify, extend, straighten, or replace the artwork, and do not correct or flatten its perspective.\n"
                "- Build the surrounding floor, wall, and architecture so their vanishing lines and verticals match the same extreme upward camera angle already visible in the artwork's protected perspective plane. The room must look photographed from near floor level looking up and must respect the perspective marked by the protected artwork plate.\n"
                "- The newly generated room must harmonize with the artwork's color palette, tone, and mood, with realistic lighting and soft contact shadows at the artwork edges.\n"
                "- The artwork must look like a real physical painting installed in the room, not pasted or floating."
            )
        elif not gemini_reference_images and is_mockup and MOCKUP_USE_PRECOMPOSITION:
            # Check camera perspective direction
            warp_dir = detect_perspective_side(args.prompt)
                
            if warp_dir:
                # Apply 3/4 perspective skew
                pb = [(0, 0), (0, h), (w, h), (w, 0)]
                if warp_dir == "left":
                    # Compress horizontal width to 70% for a steeper 3/4 perspective to resolve visual stretching
                    pa = [
                        (0, 0),
                        (0, h),
                        (int(w * 0.70), int(h * 0.85)),
                        (int(w * 0.70), int(h * 0.15))
                    ]
                    target_size = (int(w * 0.70), h)
                else:
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
            
            width_cm = None
            height_cm = None
            long_side_cm = None
            fill_ratio = prompt_float_control(args.prompt, "mockup_fill_default", 0.35)
            if match:
                try:
                    width_cm = float(match.group(1))
                    height_cm = float(match.group(2))
                    long_side_cm = max(width_cm, height_cm)
                    
                    if long_side_cm <= 45:
                        fill_ratio = prompt_float_control(args.prompt, "mockup_fill_long_side_le_45", 0.18)
                    elif long_side_cm <= 80:
                        fill_ratio = prompt_float_control(args.prompt, "mockup_fill_long_side_le_80", 0.25)
                    elif long_side_cm <= 120:
                        fill_ratio = prompt_float_control(args.prompt, "mockup_fill_long_side_le_120", 0.32)
                    elif long_side_cm <= 160:
                        fill_ratio = prompt_float_control(args.prompt, "mockup_fill_long_side_le_160", 0.38)
                    elif long_side_cm <= 220:
                        fill_ratio = prompt_float_control(args.prompt, "mockup_fill_long_side_le_220", 0.48)
                    else:
                        fill_ratio = prompt_float_control(args.prompt, "mockup_fill_long_side_gt_220", 0.58)
                except Exception:
                    pass
            
            # Apply mathematical scale correction if size_override is present in the prompt
            size_match = re.search(r"ARTWORK SIZE CORRECTION FOR THIS REGENERATION:\s*-\s*Make the artwork appear (\d+)%\s+(larger|smaller)", args.prompt)
            if size_match:
                try:
                    percent_val = float(size_match.group(1))
                    direction_val = size_match.group(2)
                    correction_factor = 1.0 + (percent_val / 100.0) if direction_val == 'larger' else 1.0 - (percent_val / 100.0)
                    fill_ratio *= correction_factor
                except Exception as e:
                    print(f"[WARN] Failed to apply scale correction: {e}", file=sys.stderr)
            
            # Apply Camera 15 scale multiplier if specified in the process environment
            camera_15_multiplier = os.environ.get("MOCKUP_SCALE_CAMARA_15")
            if camera_15_multiplier:
                try:
                    fill_ratio *= float(camera_15_multiplier)
                except ValueError:
                    pass
            
            # Keep human scale useful without turning the mockup into a distant room shot.
            prompt_lower = args.prompt.lower()
            # Punto #11: detección robusta de figura humana con múltiples keywords
            HUMAN_PRESENCE_KEYWORDS = [
                "standing adult", "standing human", "scale figure",
                "1.80 m", "1.55 m", "1.80m", "1.55m",
                "male figure", "female figure", "human figure",
                "standing man", "standing woman",
                "scale reference", "full-body scale",
                "discreet standing", "standing person",
            ]
            has_human = any(kw in prompt_lower for kw in HUMAN_PRESENCE_KEYWORDS)
            human_line = re.search(r"^\s*-\s*Human Figure:\s*(.+?)\s*$", args.prompt, re.IGNORECASE | re.MULTILINE)
            human_text = human_line.group(1).lower() if human_line else ""
            has_human = human_text != "" and "do not include" not in human_text and "none" not in human_text
            if has_human:
                fill_ratio *= prompt_float_control(args.prompt, "mockup_human_scale_multiplier", 0.50)
                
            # Internal safety limits keep the pre-composed reference valid even when ADMIN leaves renderer fields empty.
            fill_min = prompt_float_control(args.prompt, "mockup_fill_min", 0.05)
            fill_max = prompt_float_control(args.prompt, "mockup_fill_max", 0.95)
            if fill_min > fill_max:
                fill_min, fill_max = fill_max, fill_min
            fill_ratio = max(fill_min, min(fill_max, fill_ratio))
            
            max_art_dim = int(canvas_size * fill_ratio)
            
            ratio = min(max_art_dim / w, max_art_dim / h)
            new_w = int(w * ratio)
            new_h = int(h * ratio)
            
            # Enforce multiples of 8 for dimensions
            new_w = (new_w // 8) * 8
            new_h = (new_h // 8) * 8
            
            # Logging the scale parameters
            try:
                log_lines = []
                log_lines.append(f"--- VERTEX BRIDGE SCALE AUDIT ---")
                if width_cm and height_cm:
                    log_lines.append(f"Real Artwork Dimensions: {width_cm} cm wide x {height_cm} cm high")
                    log_lines.append(f"Long Side: {long_side_cm} cm")
                else:
                    log_lines.append("Real Artwork Dimensions: Not provided/parsed in prompt")
                
                log_lines.append(f"Human Presence Detected: {has_human}")
                if has_human:
                    multiplier = prompt_float_control(args.prompt, "mockup_human_scale_multiplier", 0.50)
                    log_lines.append(f"Human Scale Multiplier Applied: {multiplier}")
                
                log_lines.append(f"Final fill_ratio for Canvas: {fill_ratio:.4f}")
                log_lines.append(f"Final Artwork size in 1024x1024 canvas: {new_w} px x {new_h} px")
                log_lines.append(f"Position (x, y) on canvas: {((canvas_size - new_w) // 2)}, {int((canvas_size - new_h) * 0.35)}")
                log_lines.append("---------------------------------")
                
                # Write to sys.stderr
                for line in log_lines:
                    print(f"[SCALE-AUDIT] {line}", file=sys.stderr)
                
                # Write to logs/vertex_bridge.log
                log_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), 'logs')
                if not os.path.exists(log_dir):
                    os.makedirs(log_dir, exist_ok=True)
                
                import datetime
                timestamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                with open(os.path.join(log_dir, 'vertex_bridge.log'), 'a', encoding='utf-8') as f:
                    f.write(f"[{timestamp}] JOB: {os.path.basename(args.output) if args.output else 'unknown'}\n")
                    for line in log_lines:
                        f.write(f"  {line}\n")
            except Exception as le:
                print(f"[WARN] Failed to write scale audit log: {le}", file=sys.stderr)
            
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
                "- Keep the artwork surface itself unchanged. Do not repaint, reinterpret, alter, crop, mirror, rotate, recolor, simplify, extend, or replace the artwork.\n"
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
            max_dim = 1024
            if new_w > max_dim or new_h > max_dim:
                ratio = min(max_dim / new_w, max_dim / new_h)
                new_w = int(new_w * ratio)
                new_h = int(new_h * ratio)
                # Re-enforce multiples of 8
                new_w = (new_w // 8) * 8
                new_h = (new_h // 8) * 8
                
            if (new_w, new_h) != (w, h):
                pil_img = pil_img.resize((new_w, new_h), Image.Resampling.LANCZOS)

    # ------------------ CALL MODEL ------------------
    if is_gemini_image:
        # Map Admin plan names to the exact Vertex AI publisher model paths.
        gemini_image_models = {
            "gemini-3.1-flash-image": "publishers/google/models/gemini-3.1-flash-image",
            "gemini-3-pro-image": "publishers/google/models/gemini-3-pro-image",
            "gemini-2.5-flash-image": "publishers/google/models/gemini-2.5-flash-image",
        }
        selected_model = model_lower if model_lower in gemini_image_models else "gemini-3.1-flash-image"
            
        gemini_prompt = args.prompt
        contents = []
        if gemini_reference_images:
            for idx, ref_img in enumerate(gemini_reference_images, start=1):
                img_rgb = ref_img
                if img_rgb.mode != "RGB":
                    img_rgb = img_rgb.convert("RGB")
                contents.append(img_rgb)
                print(f"[DEBUG] Gemini reference image {idx}: size={img_rgb.size}, mode={img_rgb.mode}", file=sys.stderr)
        elif pil_img:
            # Convert pre-composed image to RGB for Gemini generate_content
            img_rgb = pil_img
            if img_rgb.mode != "RGB":
                img_rgb = img_rgb.convert("RGB")
            
            # Prepend physical scaling/placement instructions for mockups
            if is_mockup and MOCKUP_USE_PRECOMPOSITION:
                gemini_prompt = (
                    "The input image is a reference showing the artwork already correctly sized and positioned on a neutral wall.\n"
                    "You must preserve this artwork exactly as it is (its aspect ratio, perspective angle, center position, and relative size must not be changed).\n"
                    "Replace the surrounding grey wall area with a premium gallery/collector room, blending the lighting and shadows seamlessly around the artwork edges.\n\n"
                    + args.prompt
                )
            contents.append(img_rgb)
            print(f"[DEBUG] Image details: size={img_rgb.size}, mode={img_rgb.mode}", file=sys.stderr)
            
        contents.insert(0, gemini_prompt)
        print(f"[DEBUG] Selected Gemini Image Plan: {selected_model}", file=sys.stderr)
        print(f"[DEBUG] Prompt Length: {len(gemini_prompt)} characters", file=sys.stderr)
        print(f"[DEBUG] Contents List Length: {len(contents)} items", file=sys.stderr)

        response = None
        last_error = None
        for model_key in image_model_fallback_chain(selected_model, gemini_image_models):
            resolved_model = gemini_image_models[model_key]
            print(f"[DEBUG] Resolved Model: {resolved_model}", file=sys.stderr)
            try:
                response = call_with_retry(
                    lambda m=resolved_model: client.models.generate_content(
                        model=m,
                        contents=contents
                    ),
                    max_retries=4
                )
                break
            except Exception as e:
                last_error = e
                if is_rate_limit_error(e) and model_key != "gemini-2.5-flash-image":
                    print(
                        f"[WARN] Gemini Image quota exhausted for {model_key}. Trying fallback model...",
                        file=sys.stderr
                    )
                    continue
                raise e

        if response is None:
            raise last_error or RuntimeError(
                "No Gemini Image model could produce a response."
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
                
                if hasattr(img, 'image_bytes') and img.image_bytes:
                    with open(args.output, "wb") as f:
                        f.write(img.image_bytes)
                elif hasattr(img, 'save'):
                    img.save(args.output)
                else:
                    raise RuntimeError("Unknown image object type returned from SDK.")
                    
                print(f"SUCCESS: Image saved to {args.output}")
                img_found = True
                break
                
        if not img_found:
            raise RuntimeError("No image was returned in the Gemini response parts.")
            
        return
        
    elif args.image and (MOCKUP_PROMPT_FIRST_NO_MASK_MODE or not (is_mockup and not MOCKUP_USE_PRECOMPOSITION and not MOCKUP_USE_BACKGROUND_EDIT)):
        # Image-to-image or background replacement using edit_image (Imagen models)
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
        
        reference_images_list = []
        if is_mockup and MOCKUP_PROMPT_FIRST_NO_MASK_MODE:
            subject_ref = types.SubjectReferenceImage(
                reference_id=1,
                reference_image=types.Image(
                    image_bytes=image_bytes,
                    mime_type=mime_type
                ),
                config=types.SubjectReferenceConfig(
                    subject_type="SUBJECT_TYPE_PRODUCT"
                )
            )
            reference_images_list = [subject_ref]
            edit_mode = None
            
            # Text-based preservation directives
            if "ARTWORK PRESERVATION DIRECTIVES" not in args.prompt:
                args.prompt += (
                    "\n\nARTWORK PRESERVATION DIRECTIVES:\n"
                    "- The provided artwork image is the authoritative visual reference for the artwork. Recreate the same artwork faithfully inside the mockup scene. Preserve its composition, colors, marks, texture, proportions and visual identity. Do not repaint, redesign, simplify, crop, mirror, recolor or reinterpret the artwork. The artwork may only undergo natural geometric perspective caused by the requested camera view."
                )
        else:
            reference_images_list = [raw_ref]
            if is_mockup and mask_bytes is not None:
                try:
                    mask_dilation = float(MOCKUP_MASK_DILATION) if MOCKUP_MASK_DILATION != "" else 0.015
                except ValueError:
                    mask_dilation = 0.015
                mask_ref = types.MaskReferenceImage(
                    reference_id=2,
                    reference_image=types.Image(
                        image_bytes=mask_bytes,
                        mime_type="image/png"
                    ),
                    config=types.MaskReferenceConfig(
                        mask_mode="MASK_MODE_USER_PROVIDED",
                        mask_dilation=mask_dilation
                    )
                )
                reference_images_list.append(mask_ref)
                edit_mode = "EDIT_MODE_INPAINT_INSERTION"

                if style_reference_path:
                    style_img = Image.open(style_reference_path).convert("RGB")

                    if MOCKUP_WORLD_MOTHER_SCALE:
                        try:
                            zoom = float(MOCKUP_WORLD_MOTHER_SCALE)
                        except ValueError:
                            zoom = 1.0
                        zoom = max(1.0, min(3.0, zoom))
                        if zoom > 1.0:
                            sw, sh = style_img.size
                            crop_w, crop_h = sw / zoom, sh / zoom
                            left = (sw - crop_w) / 2
                            top = (sh - crop_h) / 2
                            style_img = style_img.crop((left, top, left + crop_w, top + crop_h)).resize((sw, sh), Image.Resampling.LANCZOS)
                            print(f"[DEBUG] World mother style reference zoomed x{zoom:.2f} (center crop).", file=sys.stderr)

                    style_byte_arr = io.BytesIO()
                    style_img.save(style_byte_arr, format='PNG')
                    style_ref = types.StyleReferenceImage(
                        reference_id=3,
                        reference_image=types.Image(
                            image_bytes=style_byte_arr.getvalue(),
                            mime_type="image/png"
                        ),
                        config=types.StyleReferenceConfig(
                            style_description="world mother environment: wall material, floor material, architectural mass, and light color temperature"
                        )
                    )
                    reference_images_list.append(style_ref)
                    # Imagen's reference-image API only applies a reference when the prompt
                    # text explicitly cites its bracket tag; without this the style image is
                    # attached but silently unused.
                    args.prompt += (
                        "\n\nWORLD MOTHER STYLE REFERENCE [3]:\n"
                        "- Reference [3] is the world mother environment photo. Reproduce its wall surface material, floor material, color palette, and light color temperature in the newly generated room around artwork [1].\n"
                        "- Do not copy [3]'s camera angle, layout, furniture placement, window placement, or room geometry — only its material, palette, and light quality."
                    )
                    print("[DEBUG] Attached world mother image as StyleReferenceImage [3] for masked inpainting.", file=sys.stderr)
            elif is_mockup and MOCKUP_USE_BACKGROUND_EDIT:
                mask_ref = types.MaskReferenceImage(
                    reference_id=2,
                    config=types.MaskReferenceConfig(
                        mask_mode="MASK_MODE_BACKGROUND"
                    )
                )
                reference_images_list.append(mask_ref)
                edit_mode = None
            elif not is_mockup:
                mask_ref = types.MaskReferenceImage(
                    reference_id=2,
                    config=types.MaskReferenceConfig(
                        mask_mode="MASK_MODE_BACKGROUND"
                    )
                )
                reference_images_list.append(mask_ref)
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
                reference_images=reference_images_list,
                config=types.EditImageConfig(**config_args)
            )
        )
    else:
        # Text-to-image generation (Imagen models)
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

    # Write execution log
    try:
        log_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), 'logs')
        os.makedirs(log_dir, exist_ok=True)
        import datetime
        timestamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        
        # Read dimensions
        input_size_str = "N/A"
        if pil_img:
            input_size_str = f"{pil_img.width}x{pil_img.height}"
            
        output_size_str = "1024x1024" # Default for Imagen
        
        with open(os.path.join(log_dir, 'vertex_bridge.log'), 'a', encoding='utf-8') as f:
            f.write(f"[{timestamp}] JOB: {os.path.basename(args.output) if args.output else 'unknown'}\n")
            if is_mockup and MOCKUP_PROMPT_FIRST_MODE and MOCKUP_PROMPT_FIRST_NO_MASK_MODE:
                f.write(f"  MOCKUP_PROMPT_FIRST_MODE=true\n")
                f.write(f"  MOCKUP_PROMPT_FIRST_NO_MASK_MODE=true\n")
                f.write(f"  use_precomposition=false\n")
                f.write(f"  use_background_edit=false\n")
                f.write(f"  mask_mode=none\n")
                f.write(f"  external_mask_used=false\n")
                f.write(f"  grey_canvas_used=false\n")
                f.write(f"  inpainting_used=false\n")
                f.write(f"  generation_mode=pure_reference\n")
                f.write(f"  model_used={model}\n")
                f.write(f"  input_image_size={input_size_str}\n")
                f.write(f"  output_image_size={output_size_str}\n")
            else:
                f.write(f"  MOCKUP_PROMPT_FIRST_MODE={'true' if MOCKUP_PROMPT_FIRST_MODE else 'false'}\n")
                f.write(f"  MOCKUP_PROMPT_FIRST_NO_MASK_MODE={'true' if MOCKUP_PROMPT_FIRST_NO_MASK_MODE else 'false'}\n")
                f.write(f"  use_precomposition={'true' if MOCKUP_USE_PRECOMPOSITION else 'false'}\n")
                f.write(f"  use_background_edit={'true' if MOCKUP_USE_BACKGROUND_EDIT else 'false'}\n")
                f.write(f"  mask_mode={'user_provided' if (mask_bytes is not None) else ('background' if MOCKUP_USE_BACKGROUND_EDIT else 'none')}\n")
                f.write(f"  external_mask_used={'true' if (mask_bytes is not None) else 'false'}\n")
                f.write(f"  grey_canvas_used={'true' if MOCKUP_USE_PRECOMPOSITION else 'false'}\n")
                f.write(f"  inpainting_used={'true' if (mask_bytes is not None) else 'false'}\n")
                f.write(f"  generation_mode={'background_edit' if MOCKUP_USE_BACKGROUND_EDIT else 'text_to_image'}\n")
                f.write(f"  model_used={model}\n")
                f.write(f"  input_image_size={input_size_str}\n")
                f.write(f"  output_image_size={output_size_str}\n")
    except Exception as le:
        print(f"[WARN] Failed to write execution log: {le}", file=sys.stderr)


def handle_embed_image(args):
    """Compute multimodal embeddings for one image or a batch list.

    Uses the regional multimodalembedding@001 REST endpoint (the shared genai
    client targets location "global", which does not serve this model).
    Batch mode reads one path per line ("id<TAB>path" or just "path") and
    writes JSONL: {"id":..., "path":..., "embedding":[...]} per line.
    """
    import base64
    import io
    import json
    import httpx
    import google.auth
    import google.auth.transport.requests

    project_id = os.environ.get('VERTEX_PROJECT_ID', 'project-3c7fb926-f021-47c6-9cc')
    location = os.environ.get('VERTEX_EMBED_LOCATION', 'us-central1')
    url = (f"https://{location}-aiplatform.googleapis.com/v1/projects/{project_id}"
           f"/locations/{location}/publishers/google/models/multimodalembedding@001:predict")

    credentials, _ = google.auth.default(scopes=["https://www.googleapis.com/auth/cloud-platform"])
    auth_request = google.auth.transport.requests.Request()

    def autocrop_artwork(img):
        """Recorta el lienzo dentro de una foto de producto (obra contra fondo neutro).

        Detecta el bounding box de píxeles que difieren del color de fondo (promedio de
        esquinas) y luego lo contrae un 7% por lado para descartar marco y sombra, de modo
        que el embedding capture la pintura y no la escena.
        """
        small = img.copy().convert("L")
        small.thumbnail((256, 256))
        w, h = small.size
        px = small.load()
        # Energía de gradiente por fila/columna: la pared es lisa (aunque tenga viñeteado,
        # el degradé es suave); la pintura y su marco concentran los bordes.
        col_energy = [0.0] * w
        row_energy = [0.0] * h
        for y in range(h - 1):
            for x in range(w - 1):
                g = abs(px[x + 1, y] - px[x, y]) + abs(px[x, y + 1] - px[x, y])
                if g > 8:
                    col_energy[x] += g
                    row_energy[y] += g

        def span(energies):
            peak = sorted(energies)[int(len(energies) * 0.95)]
            if peak <= 0:
                return None
            threshold = peak * 0.12
            lo = next((i for i, e in enumerate(energies) if e >= threshold), None)
            hi = next((i for i in range(len(energies) - 1, -1, -1) if energies[i] >= threshold), None)
            return (lo, hi) if lo is not None and hi is not None and hi > lo else None

        sx = span(col_energy)
        sy = span(row_energy)
        if sx is None or sy is None:
            return img
        min_x, max_x = sx
        min_y, max_y = sy
        box_w, box_h = max_x - min_x, max_y - min_y
        if box_w < w * 0.2 or box_h < h * 0.2:
            return img
        inset_x, inset_y = box_w * 0.07, box_h * 0.07
        scale_x, scale_y = img.width / w, img.height / h
        left = int((min_x + inset_x) * scale_x)
        top = int((min_y + inset_y) * scale_y)
        right = int((max_x - inset_x) * scale_x)
        bottom = int((max_y - inset_y) * scale_y)
        if right - left < 20 or bottom - top < 20:
            return img
        return img.crop((left, top, right, bottom))

    def image_payload(path):
        img = Image.open(path)
        img = img.convert("RGB")
        if getattr(args, 'crop_artwork', False):
            img = autocrop_artwork(img)
        img.thumbnail((512, 512))
        buf = io.BytesIO()
        img.save(buf, format="JPEG", quality=88)
        return base64.b64encode(buf.getvalue()).decode("ascii")

    if getattr(args, 'crop_debug', None):
        img = Image.open(args.image).convert("RGB")
        autocrop_artwork(img).save(args.crop_debug)
        print(json.dumps({"saved": args.crop_debug}))
        return

    def embed_one(path, client):
        body = {
            "instances": [{"image": {"bytesBase64Encoded": image_payload(path)}}],
            "parameters": {"dimension": 1408},
        }
        last_error = None
        for attempt in range(5):
            if not credentials.valid:
                credentials.refresh(auth_request)
            response = client.post(url, json=body, headers={"Authorization": f"Bearer {credentials.token}"})
            if response.status_code == 200:
                return response.json()["predictions"][0]["imageEmbedding"]
            last_error = f"HTTP {response.status_code}: {response.text[:300]}"
            if response.status_code in (429, 500, 502, 503):
                sleep_time = min(24, (2 ** attempt) * 3) + random.uniform(0.5, 2)
                print(f"Embed retry in {sleep_time:.1f}s ({last_error})", file=sys.stderr)
                time.sleep(sleep_time)
                continue
            break
        raise RuntimeError(f"Embedding failed for {path}: {last_error}")

    with httpx.Client(timeout=120.0) as client:
        if args.list:
            with open(args.list, "r", encoding="utf-8") as f:
                entries = [line.strip() for line in f if line.strip()]
            out = open(args.output, "w", encoding="utf-8") if args.output else sys.stdout
            done = 0
            try:
                for entry in entries:
                    if "\t" in entry:
                        item_id, path = entry.split("\t", 1)
                    else:
                        item_id, path = "", entry
                    try:
                        vector = embed_one(path, client)
                        out.write(json.dumps({"id": item_id, "path": path, "embedding": vector}) + "\n")
                    except Exception as e:
                        out.write(json.dumps({"id": item_id, "path": path, "error": str(e)}) + "\n")
                    out.flush()
                    done += 1
                    print(f"progress {done}/{len(entries)}", file=sys.stderr)
            finally:
                if out is not sys.stdout:
                    out.close()
        else:
            vector = embed_one(args.image, client)
            print(json.dumps({"path": args.image, "embedding": vector}))


def main():
    parser = argparse.ArgumentParser(description="Vertex AI CLI Bridge")
    subparsers = parser.add_subparsers(dest="command", required=True)
    
    # Subcommand: generate-text
    text_parser = subparsers.add_parser("generate-text", help="Generate text / analysis using Gemini")
    text_parser.add_argument("--prompt", type=str, help="Text prompt")
    text_parser.add_argument("--prompt-file", type=str, help="Path to file containing the prompt")
    text_parser.add_argument("--image", type=str, action="append", help="Path to the input image(s)")
    text_parser.add_argument("--model", type=str, default="gemini-2.5-flash", help="Gemini model name")
    
    # Subcommand: generate-image
    image_parser = subparsers.add_parser("generate-image", help="Generate/Edit images using Imagen or Gemini")
    image_parser.add_argument("--prompt", type=str, help="Text prompt")
    image_parser.add_argument("--prompt-file", type=str, help="Path to file containing the prompt")
    image_parser.add_argument("--image", type=str, action="append", help="Path to reference base image(s) (for editing/multimodal)")
    image_parser.add_argument("--model", type=str, help="Imagen or Gemini model name")
    image_parser.add_argument("--aspect_ratio", type=str, default="1:1", help="Aspect ratio (e.g. 1:1, 4:3, 16:9)")
    image_parser.add_argument("--output", type=str, required=True, help="Output file path where image will be saved")

    # Subcommand: embed-image
    embed_parser = subparsers.add_parser("embed-image", help="Compute multimodal embeddings for images")
    embed_parser.add_argument("--image", type=str, help="Path to a single image")
    embed_parser.add_argument("--list", type=str, help="Path to a text file with one image per line (id<TAB>path)")
    embed_parser.add_argument("--output", type=str, help="Output JSONL path for batch mode (default stdout)")
    embed_parser.add_argument("--crop-artwork", dest="crop_artwork", action="store_true", help="Auto-crop the canvas out of product shots before embedding")
    embed_parser.add_argument("--crop-debug", dest="crop_debug", type=str, help="Save the auto-cropped image to this path and exit (no API call)")

    args = parser.parse_args()
    
    try:
        if hasattr(args, 'prompt_file') and args.prompt_file:
            with open(args.prompt_file, 'r', encoding='utf-8') as f:
                args.prompt = f.read()
        elif hasattr(args, 'prompt') and args.prompt == "-":
            # Read prompt from stdin
            args.prompt = sys.stdin.read()
            
        if args.command in ("generate-text", "generate-image") and not getattr(args, 'prompt', None):
            raise ValueError("Must provide either --prompt or --prompt-file.")

        if args.command == "generate-text":
            handle_generate_text(args)
        elif args.command == "generate-image":
            handle_generate_image(args)
        elif args.command == "embed-image":
            if not args.image and not args.list:
                raise ValueError("Must provide --image or --list.")
            handle_embed_image(args)
    except Exception as e:
        error_msg = str(e)
        is_rate_limit = "429" in error_msg or "resource has been exhausted" in error_msg.lower()
        
        if is_rate_limit:
            print(f"FATAL: API rate limit (429 RESOURCE_EXHAUSTED). Cannot continue.", file=sys.stderr)
            print(f"This usually means the quota has been exceeded. Check Google Cloud console.", file=sys.stderr)
        
        import traceback
        traceback.print_exc(file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
