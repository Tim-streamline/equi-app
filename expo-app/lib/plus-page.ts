export type PlusContent = {
  homeTitle: string; homeBody: string; homeButton: string;
  pageTitle: string; heroLabel: string; pricePeriod: string;
  heroTitle: string; heroBody: string; price: string; period: string; heroButton: string;
  stepsTitle: string; stepsButton: string; steps: { title: string; body: string }[];
  guideTitle: string; guideBody: string; guideSignature: string;
  reviewsTitle: string; reviewsAreExamples: boolean; reviews: { title: string; quote: string; name: string }[];
  benefits: string[]; priceButton: string; priceNote: string;
  faqs: { question: string; answer: string }[];
  closingLabel: string; closingTitle: string; closingBody: string; closingButton: string;
  confirmationTitle: string; confirmationPlan: string; confirmationProtocol: string; confirmationButton: string;
  welcomeTitle: string; welcomeBody: string;
  /** The FAQ for owners who are already in an EquiBoost traject. */
  memberFaqTitle: string; memberFaqBody: string; memberFaqVideoTitle: string | null;
  memberFaqs: { question: string; answer: string }[];
};
/** The published library item whose title matches `memberFaqVideoTitle`, if any. */
export type MemberFaqVideo = { id: string; format: string; title: string };
export type PlusPageData = { content: PlusContent; heroImageUrl: string; portraitImageUrl: string | null; memberFaqVideo?: MemberFaqVideo | null };
