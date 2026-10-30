/** Limits for photos attached to a booking, shared by the form and the API. */
export const PHOTO_LIMITS = {
  maxCount: 5,
  /** Whole request stays under the 4.5 MB body limit of serverless hosts. */
  maxTotalBytes: 4 * 1024 * 1024,
  /** Photos are resized in the browser to this longest side before upload. */
  maxDimension: 1600,
};
