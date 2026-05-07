import cv2
import numpy as np
import sys
import os

def preprocess_image(input_path, output_path):
    try:
        # Load image
        img = cv2.imread(input_path)
        if img is None:
            print(f"Error: Could not read image from {input_path}")
            return False

        # 1. Convert to grayscale
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

        # 2. Rescale image if too small
        height, width = gray.shape[:2]
        if height < 1000 or width < 1000:
            gray = cv2.resize(gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)

        # 3. Apply adaptive thresholding to get binary image (black text on white)
        # Using Gaussian thresholding for better results on uneven lighting
        binary = cv2.adaptiveThreshold(
            gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, 
            cv2.THRESH_BINARY, 21, 15
        )

        # 4. Noise removal (Dilation followed by Erosion to close gaps)
        kernel = np.ones((1, 1), np.uint8)
        processed = cv2.morphologyEx(binary, cv2.MORPH_OPEN, kernel)
        
        # 5. Optional: Sharpening
        # kernel_sharp = np.array([[-1,-1,-1], [-1,9,-1], [-1,-1,-1]])
        # processed = cv2.filter2D(processed, -1, kernel_sharp)

        # Save the result
        cv2.imwrite(output_path, processed)
        print(f"Success: Processed image saved to {output_path}")
        return True

    except Exception as e:
        print(f"Exception: {str(e)}")
        return False

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python preprocess_receipt.py <input_path> <output_path>")
        sys.exit(1)

    input_file = sys.argv[1]
    output_file = sys.argv[2]
    
    if preprocess_image(input_file, output_file):
        sys.exit(0)
    else:
        sys.exit(1)
